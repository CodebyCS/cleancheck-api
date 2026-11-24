<?php

use App\Modules\Tasks\Validators\TaskGenerationValidator;
use App\Modules\Tasks\Actions\CreateAssignedTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('uses only the current active default configured on the property', function () {
    $first = createServiceProvider('default-a@example.test');
    $second = createServiceProvider('default-b@example.test');
    $property = icalProperty();
    $property->update(['responsible_service_provider_id' => $first->id]);
    $validator = app(TaskGenerationValidator::class);

    expect(DB::transaction(fn () => $validator->defaultProviderId($property->id)))
        ->toBe($first->id);

    $property->update(['responsible_service_provider_id' => $second->id]);

    expect(DB::transaction(fn () => $validator->defaultProviderId($property->id)))
        ->toBe($second->id);
});

it('creates each manual and automatic task with the current default and records initial history', function () {
    $first = createServiceProvider('initial-a@example.test');
    $second = createServiceProvider('initial-b@example.test');
    $property = icalProperty(['responsible_service_provider_id' => $first->id]);
    $create = app(CreateAssignedTask::class);

    $manual = $create->execute([
        'booking_id' => 10,
        'property_id' => $property->id,
        'type' => 'checkin_inspection',
        'status' => 'pending',
        'scheduled_start_at' => now(),
    ], userWithRole('admin')->id);

    $property->update(['responsible_service_provider_id' => $second->id]);
    $automatic = $create->execute([
        'booking_id' => 11,
        'property_id' => $property->id,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => now(),
    ]);

    expect($manual->service_provider_id)->toBe($first->id)
        ->and($automatic->service_provider_id)->toBe($second->id)
        ->and($manual->assignmentHistory()->first()->user_id)->not->toBeNull()
        ->and($automatic->assignmentHistory()->first()->user_id)->toBeNull()
        ->and($manual->assignmentHistory()->first()->new_service_provider_id)->toBe($first->id)
        ->and($automatic->assignmentHistory()->first()->new_service_provider_id)->toBe($second->id);
});

it('keeps existing assignment history when the property default changes and a task is manually reassigned', function () {
    $providerA = createServiceProvider('point-two-a@example.test');
    $providerB = createServiceProvider('point-two-b@example.test');
    $providerC = createServiceProvider('point-two-c@example.test');
    $property = icalProperty(['responsible_service_provider_id' => $providerA->id]);
    $create = app(CreateAssignedTask::class);
    $attributes = [
        'property_id' => $property->id,
        'status' => 'pending',
        'scheduled_start_at' => now(),
    ];

    $oldTask = $create->execute($attributes + [
        'booking_id' => 20,
        'type' => 'checkin_inspection',
    ], userWithRole('admin')->id);
    $originalHistory = $oldTask->assignmentHistory()->firstOrFail()->only([
        'previous_service_provider_id', 'new_service_provider_id', 'user_id', 'created_at',
    ]);

    $property->update(['responsible_service_provider_id' => $providerB->id]);
    $newTask = $create->execute($attributes + [
        'booking_id' => 21,
        'type' => 'checkout_cleaning',
    ]);

    expect($oldTask->fresh()->service_provider_id)->toBe($providerA->id)
        ->and($oldTask->assignmentHistory()->count())->toBe(1)
        ->and($oldTask->assignmentHistory()->first()->only([
            'previous_service_provider_id', 'new_service_provider_id', 'user_id', 'created_at',
        ]))->toEqual($originalHistory)
        ->and($newTask->service_provider_id)->toBe($providerB->id)
        ->and($newTask->assignmentHistory()->first()->new_service_provider_id)->toBe($providerB->id)
        ->and($newTask->assignmentHistory()->first()->user_id)->toBeNull();

    $actor = userWithRole('supervisor');
    authenticateApiUser($actor);
    $this->patchJson("/api/tasks/{$oldTask->id}", [
        'service_provider_id' => $providerC->id,
    ])->assertOk()->assertJsonPath('data.service_provider_id', $providerC->id);

    $history = $oldTask->assignmentHistory()->orderBy('id')->get();
    expect($oldTask->fresh()->service_provider_id)->toBe($providerC->id)
        ->and($history)->toHaveCount(2)
        ->and($history[0]->new_service_provider_id)->toBe($providerA->id)
        ->and($history[0]->user_id)->not->toBeNull()
        ->and($history[1]->previous_service_provider_id)->toBe($providerA->id)
        ->and($history[1]->new_service_provider_id)->toBe($providerC->id)
        ->and($history[1]->user_id)->toBe($actor->id)
        ->and($history[1]->created_at)->not->toBeNull();
});

it('rolls back task creation when the initial history cannot be stored', function () {
    $provider = createServiceProvider('rollback-default@example.test');
    $property = icalProperty(['responsible_service_provider_id' => $provider->id]);

    DB::statement('DROP TABLE task_assignment_history');

    try {
        app(CreateAssignedTask::class)->execute([
            'booking_id' => 12,
            'property_id' => $property->id,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => now(),
        ]);
        $this->fail('Expected history persistence to fail.');
    } catch (\Throwable) {
        $this->assertDatabaseCount('tasks', 0);
    }
});

it('rejects an absent or inactive default instead of choosing another active provider', function (bool $inactive) {
    createServiceProvider('alternative@example.test');
    $property = icalProperty();

    if ($inactive) {
        $provider = createServiceProvider('inactive-default@example.test');
        $provider->user->update(['is_active' => false]);
        $property->update(['responsible_service_provider_id' => $provider->id]);
    }

    try {
        DB::transaction(fn () => app(TaskGenerationValidator::class)->defaultProviderId($property->id));
        $this->fail('Expected a field validation error for the property default.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('responsible_service_provider_id');
    }

    $this->assertDatabaseCount('tasks', 0);
    $this->assertDatabaseCount('task_assignment_history', 0);
})->with(['absent' => false, 'inactive' => true]);

it('rejects a nonexistent default reference without selecting a replacement', function () {
    createServiceProvider('alternative@example.test');
    $property = icalProperty();

    // Simulate a legacy dangling reference only in the isolated SQLite test transaction.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $property->update(['responsible_service_provider_id' => 999999]);

    try {
        DB::transaction(fn () => app(TaskGenerationValidator::class)->defaultProviderId($property->id));
        $this->fail('Expected a field validation error for the nonexistent default.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('responsible_service_provider_id');
    }

    $property->update(['responsible_service_provider_id' => null]);
    $this->assertDatabaseCount('tasks', 0);
    $this->assertDatabaseCount('task_assignment_history', 0);
});

it('refuses a caller supplied provider when creating a task', function (string $role) {
    $provider = createServiceProvider('caller-selected@example.test');
    authenticateApiUser(userWithRole($role));

    $this->postJson('/api/tasks', [
        'booking_id' => 999999,
        'service_provider_id' => $provider->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('service_provider_id');

    $this->assertDatabaseCount('tasks', 0);
    $this->assertDatabaseCount('task_assignment_history', 0);
})->with(['admin', 'supervisor']);

it('returns a field validation error and persists no task when manual creation has no valid default', function () {
    createServiceProvider('manual-no-fallback@example.test');
    $property = icalProperty();
    $source = BookingSource::create([
        'property_id' => $property->id,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);
    $booking = Booking::create([
        'booking_source_id' => $source->id,
        'external_uid' => 'manual-no-default',
        'source_check_in_at' => now()->addDays(5),
        'source_check_out_at' => now()->addDays(7),
        'status' => 'reserved',
    ]);

    authenticateApiUser(userWithRole('admin'));
    $this->postJson('/api/tasks', ['booking_id' => $booking->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('responsible_service_provider_id');

    $this->assertDatabaseCount('tasks', 0);
    $this->assertDatabaseCount('task_assignment_history', 0);
});
