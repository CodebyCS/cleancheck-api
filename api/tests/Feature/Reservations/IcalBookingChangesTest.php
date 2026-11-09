<?php

declare(strict_types=1);

use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Events\BookingUpdated;
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

const CHANGED_UID = 'fict-0004@cleancheck.test';

function changedBooking(): Booking
{
    return Booking::where('external_uid', CHANGED_UID)->sole();
}

beforeEach(function () {
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

it('updates the received dates when they change in the feed', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-changed.ics');

    ($this->sync)();
    $result = ($this->sync)();

    $booking = changedBooking();

    expect($booking->source_check_in_at->format('Y-m-d'))->toBe('2026-11-03')
        ->and($booking->source_check_out_at->format('Y-m-d'))->toBe('2026-11-06')
        ->and($result->updated)->toHaveCount(1)
        ->and($result->existing)->toHaveCount(2)
        ->and(SyncRun::latest('id')->first()->updated_count)->toBe(1);

    Event::assertDispatchedTimes(BookingUpdated::class, 1);
    Event::assertDispatched(BookingUpdated::class, fn (BookingUpdated $event) => $event->bookingId === $booking->id);
});

it('keeps the local adjustment when the received dates change', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-changed.ics');

    ($this->sync)();
    changedBooking()->update(['overridden_check_out_at' => '2026-11-07']);

    ($this->sync)();

    $booking = changedBooking();

    expect($booking->source_check_out_at->format('Y-m-d'))->toBe('2026-11-06')
        ->and($booking->overridden_check_out_at->format('Y-m-d'))->toBe('2026-11-07');
});

it('does not announce an update when nothing changed', function () {
    feedSequence('airbnb-valid.ics', 'airbnb-valid.ics');

    ($this->sync)();
    $result = ($this->sync)();

    expect($result->updated)->toBe([])
        ->and(SyncRun::latest('id')->first()->updated_count)->toBe(0);

    Event::assertNotDispatched(BookingUpdated::class);
});

it('never updates dates on a manual file import', function () {
    $propertyId = $this->source->property_id;
    $adapter = app(AirbnbAdapter::class);
    $importService = app(BookingImportService::class);

    $importService->import($propertyId, 'airbnb', $adapter->parseFeed(icalFixture('airbnb-valid.ics'), $propertyId));
    $result = $importService->import($propertyId, 'airbnb', $adapter->parseFeed(icalFixture('airbnb-changed.ics'), $propertyId));

    expect($result->updated)->toBe([])
        ->and(changedBooking()->source_check_in_at->format('Y-m-d'))->toBe('2026-11-02');

    Event::assertNotDispatched(BookingUpdated::class);
});