<?php

declare(strict_types=1);

use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Jobs\SyncBookingSource;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function scheduledSource(array $attributes = []): BookingSource
{
    return BookingSource::create(array_merge([
        'property_id' => icalProperty()->id,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://calendar.example.com/feed.ics?s=fict',
        'is_active' => true,
    ], $attributes));
}

beforeEach(function () {
    Event::fake([BookingImported::class]);
    Http::preventStrayRequests();
    fakeDns(['calendar.example.com' => ['52.10.20.30']]);
});

it('queues one sync job per active url source', function () {
    Queue::fake();
    $active = scheduledSource();
    scheduledSource(['is_active' => false]);
    scheduledSource(['type' => 'file', 'url' => null]);

    $this->artisan('reservations:sync')->assertSuccessful();

    Queue::assertPushed(SyncBookingSource::class, 1);
    Queue::assertPushed(
        SyncBookingSource::class,
        fn (SyncBookingSource $job) => $job->bookingSourceId === $active->id,
    );
});

it('syncs right away with the now option', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);
    scheduledSource();

    $this->artisan('reservations:sync --now')->assertSuccessful();

    expect(Booking::count())->toBe(3)
        ->and(SyncRun::sole()->status)->toBe('success');
});

it('records a failed sync without failing the job', function () {
    Http::fake(['calendar.example.com/*' => Http::failedConnection()]);
    $source = scheduledSource();

    SyncBookingSource::dispatchSync($source->id);

    expect(SyncRun::sole()->status)->toBe('failed');
});

it('skips a source that was disabled after the job was queued', function () {
    Http::fake();
    $source = scheduledSource();
    $source->update(['is_active' => false]);

    SyncBookingSource::dispatchSync($source->id);

    Http::assertNothingSent();
    expect(SyncRun::count())->toBe(0);
});

it('schedules the sync command', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('reservations:sync')
        ->assertSuccessful();
});