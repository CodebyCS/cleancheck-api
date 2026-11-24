<?php

use App\Modules\Tasks\Validators\TaskGenerationValidator;
use App\Modules\Tasks\Actions\CreateAssignedTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
