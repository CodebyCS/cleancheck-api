<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Exceptions\SyncInProgressException;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use App\Modules\Reservations\Services\IcalSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function recoveryRunStatuses(): array
{
    return SyncRun::orderBy('id')->pluck('status')->all();
}

function recoveryBooking(string $externalUid): Booking
{
    return Booking::where('external_uid', $externalUid)->sole();
}

beforeEach(function () {
    // Every booking in the fixtures is still in the future on this date.
    $this->travelTo('2026-10-01 10:00');

    Event::fake([BookingImported::class, BookingUpdated::class, BookingCancelled::class]);
    Http::preventStrayRequests();
    fakeDns(['calendar.example.com' => ['52.10.20.30']]);

    $this->source = BookingSource::create([
        'property_id' => icalProperty()->id,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://calendar.example.com/feed.ics?s=fict',
        'is_active' => true,
    ]);

    $this->sync = fn () => app(IcalSyncService::class)->sync($this->source);
});

it('converges to the feed after the calendar server was down', function () {
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->pushStatus(503)
        ->push(icalFixture('airbnb-valid.ics'))]);

    expect($this->sync)->toThrow(IcalFetchException::class);
    expect(Booking::count())->toBe(0);

    ($this->sync)();

    expect(Booking::count())->toBe(3)
        ->and(recoveryRunStatuses())->toBe(['failed', 'success']);
});

it('does not cancel bookings when an invalid feed arrives between two good ones', function () {
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->push(icalFixture('airbnb-valid.ics'))
        ->push('<html>Not a calendar</html>')
        ->push(icalFixture('airbnb-valid.ics'))]);

    ($this->sync)();
    expect($this->sync)->toThrow(InvalidIcalException::class);
    ($this->sync)();

    expect(Booking::count())->toBe(3)
        ->and(Booking::where('status', ReservationStatus::Cancelled)->count())->toBe(0)
        ->and(recoveryRunStatuses())->toBe(['success', 'failed', 'success']);

    Event::assertNotDispatched(BookingCancelled::class);
    Event::assertNotDispatched(BookingUpdated::class);
});

it('applies a change that arrived while the source was failing', function () {
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->push(icalFixture('airbnb-valid.ics'))
        ->pushStatus(503)
        ->push(icalFixture('airbnb-changed.ics'))]);

    ($this->sync)();
    expect($this->sync)->toThrow(IcalFetchException::class);
    ($this->sync)();

    expect(recoveryBooking('fict-0004@cleancheck.test')->source_check_in_at->toDateString())->toBe('2026-11-03')
        ->and(Booking::count())->toBe(3);

    Event::assertDispatchedTimes(BookingUpdated::class, 1);
});

it('cancels a booking that left the feed only once a read succeeds', function () {
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->push(icalFixture('airbnb-valid.ics'))
        ->pushStatus(503)
        ->push(icalFixture('airbnb-cancelled.ics'))]);

    ($this->sync)();
    expect($this->sync)->toThrow(IcalFetchException::class);

    expect(recoveryBooking('fict-0002@cleancheck.test')->status)->toBe(ReservationStatus::Reserved);

    ($this->sync)();

    expect(recoveryBooking('fict-0002@cleancheck.test')->status)->toBe(ReservationStatus::Cancelled);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('closes a run that was interrupted before finishing', function () {
    $interrupted = SyncRun::create([
        'booking_source_id' => $this->source->id,
        'status' => 'running',
        'started_at' => now()->subMinutes(40),
    ]);
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);

    ($this->sync)();

    $interrupted->refresh();
    expect($interrupted->status)->toBe('failed')
        ->and($interrupted->error_message)->toBe('Sincronização interrompida antes de terminar.')
        ->and($interrupted->finished_at)->not->toBeNull()
        ->and(recoveryRunStatuses())->toBe(['failed', 'success']);
});

it('leaves a running run alone while another sync holds the lock', function () {
    $running = SyncRun::create([
        'booking_source_id' => $this->source->id,
        'status' => 'running',
        'started_at' => now(),
    ]);
    Http::fake();
    Cache::lock(IcalSyncService::lockKey($this->source->id), 60)->get();

    expect($this->sync)->toThrow(SyncInProgressException::class);

    expect($running->refresh()->status)->toBe('running');
});

it('records a generic message and logs the details when the import fails unexpectedly', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);
    Log::spy();

    // Simulates a database failure in the middle of the import.
    Schema::drop('bookings');

    expect($this->sync)->toThrow(QueryException::class);

    $run = SyncRun::sole();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toStartWith('Erro interno ao importar as reservas.')
        ->and($run->error_message)->toContain("#{$run->id}")
        ->and($run->error_message)->not->toContain('SQL');

    Log::shouldHaveReceived('error')->once();
});