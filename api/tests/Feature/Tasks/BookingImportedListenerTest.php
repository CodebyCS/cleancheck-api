<?php

use App\Models\Task;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('creates checkout cleaning tasks when bookings are imported', function () {
    $provider = createServiceProvider('import-default@example.test');
    $property = icalProperty(['responsible_service_provider_id' => $provider->id]);

    $adapter = new AirbnbAdapter();
    $parseResult = $adapter->parseFeed(
        icalFixture('airbnb-valid.ics'),
        $property->id,
    );

    $importResult = app(BookingImportService::class)->import(
        $property->id,
        $adapter->channel(),
        $parseResult,
    );

    expect($importResult->created)->toHaveCount(3);
    expect(
        Task::query()
            ->where('type', 'checkout_cleaning')
            ->count()
    )->toBe(3);

    foreach ($importResult->created as $booking) {
        $this->assertDatabaseHas('tasks', [
            'booking_id' => $booking->id,
            'property_id' => $property->id,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
        ]);
    }
});

it('does not duplicate checkout tasks when bookings are reimported', function () {
    $provider = createServiceProvider('import-default@example.test');
    $property = icalProperty(['responsible_service_provider_id' => $provider->id]);

    $adapter = new AirbnbAdapter();
    $parseResult = $adapter->parseFeed(
        icalFixture('airbnb-valid.ics'),
        $property->id,
    );

    $importService = app(BookingImportService::class);

    $importService->import(
        $property->id,
        $adapter->channel(),
        $parseResult,
    );

    $importService->import(
        $property->id,
        $adapter->channel(),
        $parseResult,
    );

    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseCount('tasks', 3);
});

it('preserves imported bookings and logs task creation failure until a valid default is configured', function () {
    Log::spy();
    $property = icalProperty();
    $adapter = new AirbnbAdapter();
    $parsed = $adapter->parseFeed(icalFixture('airbnb-valid.ics'), $property->id);
    $result = app(BookingImportService::class)->import($property->id, $adapter->channel(), $parsed);

    expect($result->created)->toHaveCount(3)
        ->and(Task::query()->count())->toBe(0);
    $this->assertDatabaseCount('bookings', 3);

    $provider = createServiceProvider('fixed-import-default@example.test');
    $property->update(['responsible_service_provider_id' => $provider->id]);
    app(BookingImportService::class)->import($property->id, $adapter->channel(), $parsed);

    expect(Task::query()->count())->toBe(3);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});
