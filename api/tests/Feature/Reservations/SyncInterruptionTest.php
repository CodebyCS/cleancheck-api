<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Exceptions\SyncInProgressException;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use App\Modules\Reservations\Services\IcalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function interruptionSource(array $propertyAttributes = []): BookingSource
{
    return BookingSource::create([
        'property_id' => icalProperty($propertyAttributes)->id,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://calendar.example.com/feed.ics?s=fict',
        'is_active' => true,
    ]);
}

beforeEach(function () {
    // Every booking in the fixtures is still in the future on this date.
    $this->travelTo('2026-10-01 10:00');

    Http::preventStrayRequests();
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);
    fakeDns(['calendar.example.com' => ['52.10.20.30']]);
});

it('recovers once the lock of a killed worker expires', function () {
    Event::fake([BookingImported::class, BookingUpdated::class, BookingCancelled::class]);
    $source = interruptionSource();

    // A worker took the lock, opened a run and died: nobody releases either of them.
    Cache::lock(IcalSyncService::lockKey($source->id), 300)->get();
    SyncRun::create(['booking_source_id' => $source->id, 'status' => 'running', 'started_at' => now()]);

    expect(fn () => app(IcalSyncService::class)->sync($source))->toThrow(SyncInProgressException::class);

    // The lock frees itself after 5 minutes; the next sync closes the dead run and converges.
    $this->travel(301)->seconds();
    app(IcalSyncService::class)->sync($source);

    expect(SyncRun::orderBy('id')->pluck('status')->all())->toBe(['failed', 'success'])
        ->and(SyncRun::first()->error_message)->toBe('Sincronização interrompida antes de terminar.')
        ->and(Booking::count())->toBe(3);
});

it('keeps no partial bookings when the import dies halfway and converges on the next sync', function () {
    Event::fake([BookingImported::class, BookingUpdated::class, BookingCancelled::class]);
    $source = interruptionSource();
    $crashed = false;

    // The process dies after the first booking is written, in the middle of the transaction.
    Booking::created(function () use (&$crashed): void {
        if (! $crashed) {
            $crashed = true;

            throw new RuntimeException('Worker killed');
        }
    });

    expect(fn () => app(IcalSyncService::class)->sync($source))->toThrow(RuntimeException::class);
    expect(Booking::count())->toBe(0);

    app(IcalSyncService::class)->sync($source);

    expect(Booking::count())->toBe(3)
        ->and(Booking::distinct()->count('external_uid'))->toBe(3)
        ->and(SyncRun::orderBy('id')->pluck('status')->all())->toBe(['failed', 'success']);
});

it('creates the tasks lost by a failed queue on the next sync, without duplicates', function () {
    $provider = createServiceProvider('interruption@example.test');
    $source = interruptionSource(['responsible_service_provider_id' => $provider->id]);

    // First sync while the queue loses its jobs: bookings are saved, their task listeners never run.
    config(['queue.default' => 'null']);
    app(IcalSyncService::class)->sync($source);
    expect(Task::count())->toBe(0);

    // Queue back: existing bookings are announced again and the missing tasks appear once.
    config(['queue.default' => 'sync']);
    app(IcalSyncService::class)->sync($source);
    app(IcalSyncService::class)->sync($source);

    expect(Booking::count())->toBe(3)
        ->and(Task::where('type', 'checkout_cleaning')->count())->toBe(3);
});

it('does not bring back a task the team deleted on purpose', function () {
    $provider = createServiceProvider('interruption-deleted@example.test');
    $source = interruptionSource(['responsible_service_provider_id' => $provider->id]);

    app(IcalSyncService::class)->sync($source);
    Task::where('type', 'checkout_cleaning')->first()->delete();

    app(IcalSyncService::class)->sync($source);

    expect(Task::where('type', 'checkout_cleaning')->count())->toBe(2)
        ->and(Task::withTrashed()->where('type', 'checkout_cleaning')->count())->toBe(3);
});