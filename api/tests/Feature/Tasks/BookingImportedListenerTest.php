<?php

use App\Models\Task;
use App\Models\TaskAssignmentHistory;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $this->assertDatabaseCount('task_assignment_history', 3);
    $this->assertDatabaseCount('booking_notices', 3);

    foreach ($importResult->created as $booking) {
        $task = Task::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertDatabaseHas('tasks', [
            'booking_id' => $booking->id,
            'property_id' => $property->id,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'service_provider_id' => $provider->id,
        ]);
        $this->assertDatabaseHas('task_assignment_history', [
            'task_id' => $task->id,
            'previous_service_provider_id' => null,
            'new_service_provider_id' => $provider->id,
            'user_id' => null,
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

it('creates imported tasks unassigned when no default exists and exposes them as warnings', function () {
    config(['queue.default' => 'sync']);
    $property = icalProperty();
    $adapter = new AirbnbAdapter();
    $parsed = $adapter->parseFeed(icalFixture('airbnb-valid.ics'), $property->id);
    $result = app(BookingImportService::class)->import($property->id, $adapter->channel(), $parsed);

    expect($result->created)->toHaveCount(3)
        ->and(Task::query()->count())->toBe(3)
        ->and(Task::query()->whereNotNull('service_provider_id')->count())->toBe(0)
        ->and(TaskAssignmentHistory::query()->count())->toBe(0);
    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseCount('booking_notices', 0);

    authenticateApiUser(userWithRole('admin'));
    $unassigned = collect($this->getJson('/api/tasks/assignment-warnings')->assertOk()->json('data'));
    expect($unassigned)->toHaveCount(3)
        ->and($unassigned->pluck('warnings.0.code')->unique()->all())->toBe(['no_default_provider']);

    $provider = createServiceProvider('fixed-import-default@example.test');
    $property->update(['responsible_service_provider_id' => $provider->id]);
    app(BookingImportService::class)->import($property->id, $adapter->channel(), $parsed);

    expect(Task::query()->count())->toBe(3)
        ->and(Task::query()->whereNotNull('service_provider_id')->count())->toBe(0)
        ->and(TaskAssignmentHistory::query()->count())->toBe(0);
    foreach (Task::query()->get() as $task) {
        expect($task->service_provider_id)->toBeNull();
    }
});
