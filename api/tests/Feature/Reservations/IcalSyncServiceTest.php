<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use App\Modules\Reservations\Services\IcalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([BookingImported::class]);
    Http::preventStrayRequests();
    fakeDns(['calendar.example.com' => ['52.10.20.30']]);

    $this->source = BookingSource::create([
        'property_id' => icalProperty()->id,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://calendar.example.com/feed.ics?s=fict',
        'is_active' => true,
    ]);
});

function syncSource(BookingSource $source): void
{
    app(IcalSyncService::class)->sync($source);
}

it('imports the reserved events of a url source and records a successful run', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);

    syncSource($this->source);

    expect(Booking::where('booking_source_id', $this->source->id)->count())->toBe(3);

    $run = SyncRun::sole();
    expect($run->booking_source_id)->toBe($this->source->id)
        ->and($run->status)->toBe('success')
        ->and($run->created_count)->toBe(3)
        ->and($run->finished_at)->not->toBeNull();

    Event::assertDispatchedTimes(BookingImported::class, 3);
});

it('does not duplicate bookings when the same feed is synced again', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);

    syncSource($this->source);
    syncSource($this->source);

    expect(Booking::count())->toBe(3)
        ->and(SyncRun::where('status', 'success')->count())->toBe(2)
        ->and(SyncRun::latest('id')->first()->created_count)->toBe(0);
});

it('records a failed run when the download fails', function () {
    Http::fake(['calendar.example.com/*' => Http::failedConnection()]);

    expect(fn () => syncSource($this->source))
        ->toThrow(fn (IcalFetchException $e) => expect($e->errorCode)->toBe(IcalErrorCode::FetchFailed));

    $run = SyncRun::sole();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('calendar.example.com')
        ->and($run->finished_at)->not->toBeNull()
        ->and(Booking::count())->toBe(0);

    Event::assertNotDispatched(BookingImported::class);
});

it('records a failed run when the feed is not a valid calendar', function () {
    Http::fake(['calendar.example.com/*' => Http::response('<html>Not a calendar</html>')]);

    expect(fn () => syncSource($this->source))->toThrow(InvalidIcalException::class);

    expect(SyncRun::sole()->status)->toBe('failed')
        ->and(Booking::count())->toBe(0);

    Event::assertNotDispatched(BookingImported::class);
});

it('keeps bookings from a previous sync when a later sync fails', function () {
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->push(icalFixture('airbnb-valid.ics'))
        ->push('', 500)]);

    syncSource($this->source);

    expect(fn () => syncSource($this->source))->toThrow(IcalFetchException::class);

    expect(Booking::count())->toBe(3)
        ->and(SyncRun::orderBy('id')->pluck('status')->all())->toBe(['success', 'failed']);
});

it('refuses sources that are not active url sources', function (array $attributes) {
    $this->source->update($attributes);

    expect(fn () => syncSource($this->source))->toThrow(LogicException::class);

    expect(SyncRun::count())->toBe(0);
})->with([
    'file source' => [['type' => 'file', 'url' => null]],
    'inactive source' => [['is_active' => false]],
]);