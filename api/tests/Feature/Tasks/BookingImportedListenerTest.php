<?php

use App\Models\Task;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates checkout cleaning tasks when bookings are imported', function () {
    $property = icalProperty();

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
    $property = icalProperty();

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