<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use App\Modules\Reservations\Services\BookingImportService;
use App\Modules\Reservations\Services\IcalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CANCELLED_UID = 'fict-0002@cleancheck.test';

function feedSequence(string ...$fixtures): void
{
    $sequence = Http::sequence();

    foreach ($fixtures as $fixture) {
        $sequence->push(icalFixture($fixture));
    }

    Http::fake(['calendar.example.com/*' => $sequence]);
}

function bookingStatus(string $externalUid): ReservationStatus
{
    return Booking::where('external_uid', $externalUid)->sole()->status;
}

beforeEach(function () {
    // Every booking in the fixtures is still in the future on this date.
    $this->travelTo('2026-10-01 10:00');

    Event::fake([BookingImported::class, BookingCancelled::class]);
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

it('cancels a future booking that left the feed', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-cancelled.ics');

    ($this->sync)();
    $result = ($this->sync)();

    expect(bookingStatus(CANCELLED_UID))->toBe(ReservationStatus::Cancelled)
        ->and(bookingStatus('fict-0001@cleancheck.test'))->toBe(ReservationStatus::Reserved)
        ->and(bookingStatus('fict-0004@cleancheck.test'))->toBe(ReservationStatus::Reserved)
        ->and($result->cancelled)->toHaveCount(1)
        ->and(SyncRun::latest('id')->first()->cancelled_count)->toBe(1);

    $cancelledId = Booking::where('external_uid', CANCELLED_UID)->sole()->id;

    Event::assertDispatchedTimes(BookingCancelled::class, 1);
    Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $event) => $event->bookingId === $cancelledId);
});

it('does not cancel the same booking twice', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-cancelled.ics', 'airbnb-cancelled.ics');

    ($this->sync)();
    ($this->sync)();
    $result = ($this->sync)();

    expect($result->cancelled)->toBe([]);

    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('keeps a booking whose check-out has already passed', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-cancelled.ics');

    ($this->sync)();

    // fict-0002 checks out on 2026-10-17.
    $this->travelTo('2026-10-20 10:00');
    ($this->sync)();

    expect(bookingStatus(CANCELLED_UID))->toBe(ReservationStatus::Reserved);

    Event::assertNotDispatched(BookingCancelled::class);
});

it('cancels nothing when the download fails', function () {
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->push(icalFixture('airbnb-valid.ics'))
        ->push('', 500)]);

    ($this->sync)();

    expect($this->sync)->toThrow(IcalFetchException::class);

    expect(Booking::where('status', ReservationStatus::Cancelled)->count())->toBe(0);

    Event::assertNotDispatched(BookingCancelled::class);
});

it('cancels nothing when the feed has no events', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-no-events.ics');

    ($this->sync)();
    ($this->sync)();

    expect(Booking::where('status', ReservationStatus::Cancelled)->count())->toBe(0);

    Event::assertNotDispatched(BookingCancelled::class);
});

it('cancels nothing when an event has no UID', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-invalid.ics');

    ($this->sync)();
    ($this->sync)();

    expect(Booking::where('status', ReservationStatus::Cancelled)->count())->toBe(0);

    Event::assertNotDispatched(BookingCancelled::class);
});

it('never cancels bookings on a manual file import', function () {
    $propertyId = $this->source->property_id;
    $adapter = app(AirbnbAdapter::class);
    $importService = app(BookingImportService::class);

    $importService->import($propertyId, 'airbnb', $adapter->parseFeed(icalFixture('airbnb-valid.ics'), $propertyId));
    $result = $importService->import($propertyId, 'airbnb', $adapter->parseFeed(icalFixture('airbnb-cancelled.ics'), $propertyId));

    expect($result->cancelled)->toBe([])
        ->and(bookingStatus(CANCELLED_UID))->toBe(ReservationStatus::Reserved);

    Event::assertNotDispatched(BookingCancelled::class);
});