<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Modules\Tasks\Actions\CompleteTask;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeTaskForExecutionTest(int $serviceProviderId): Task
{
    $now = now();

    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Casa de execução',
        'address' => 'Rua de teste, 10',
        'postal_code' => '1000-010',
        'city' => 'Lisboa',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $bookingSourceId = DB::table('booking_sources')->insertGetId([
        'property_id' => $propertyId,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    $bookingId = DB::table('bookings')->insertGetId([
        'booking_source_id' => $bookingSourceId,
        'external_uid' => 'task-execution-test',
        'source_check_in_at' => $now->copy()->addDay(),
        'source_check_out_at' => $now->copy()->addDays(2),
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Task::create([
        'booking_id' => $bookingId,
        'property_id' => $propertyId,
        'service_provider_id' => $serviceProviderId,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => $now->copy()->addDay(),
    ]);
}

it('starts and completes an assigned task without requiring evidence and safely handles retries', function () {
    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000201',
    ]);
    $task = makeTaskForExecutionTest($provider->id);

    authenticateApiUser($providerUser);

    $startResponse = $this->postJson("/api/my/tasks/{$task->id}/start")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id)
        ->assertJsonPath('data.status', 'in_progress');

    $startedAt = $startResponse->json('data.started_at');
    expect($startedAt)->not->toBeNull();

    $this->postJson("/api/my/tasks/{$task->id}/start")
        ->assertOk()
        ->assertJsonPath('data.started_at', $startedAt);

    // No photo/video is sent: completion must still be allowed.
    $completeResponse = $this->postJson("/api/my/tasks/{$task->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.started_at', $startedAt);

    $completedAt = $completeResponse->json('data.completed_at');
    expect($completedAt)->not->toBeNull()->and($task->fresh()->completed_by_user_id)->toBe($providerUser->id);

    // Repeating the completion must not change the saved timestamp.
    $this->postJson("/api/my/tasks/{$task->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.completed_at', $completedAt);

    expect($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->started_at)->not->toBeNull()
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

it('rejects completing a pending task and executing a cancelled task', function () {
    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000202',
    ]);

    $pendingTask = makeTaskForExecutionTest($provider->id);
    $cancelledTask = makeTaskForExecutionTest($provider->id);
    $cancelledTask->update(['status' => 'cancelled']);

    authenticateApiUser($providerUser);

    $this->postJson("/api/my/tasks/{$pendingTask->id}/complete")
        ->assertUnprocessable();

    $this->postJson("/api/my/tasks/{$cancelledTask->id}/start")
        ->assertUnprocessable();

    $this->postJson("/api/my/tasks/{$cancelledTask->id}/complete")
        ->assertUnprocessable();

    expect($pendingTask->fresh()->status)->toBe('pending')
        ->and($pendingTask->fresh()->completed_at)->toBeNull()
        ->and($cancelledTask->fresh()->status)->toBe('cancelled')
        ->and($cancelledTask->fresh()->started_at)->toBeNull()
        ->and($cancelledTask->fresh()->completed_at)->toBeNull();
});

it('does not allow another provider to start or complete a task', function () {
    $ownerUser = userWithRole('service_provider');
    $owner = $ownerUser->serviceProvider()->create([
        'phone' => '910000203',
    ]);

    $otherProviderUser = userWithRole('service_provider');
    $otherProviderUser->serviceProvider()->create([
        'phone' => '910000204',
    ]);

    $task = makeTaskForExecutionTest($owner->id);

    authenticateApiUser($otherProviderUser);

    $this->postJson("/api/my/tasks/{$task->id}/start")
        ->assertNotFound();

    $this->postJson("/api/my/tasks/{$task->id}/complete")
        ->assertNotFound();

    expect($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->started_at)->toBeNull()
        ->and($task->fresh()->completed_at)->toBeNull();
});

it('allows admin and supervisor to complete an in-progress task without evidence', function (string $role) {
    $staffUser = userWithRole($role);

    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000205',
    ]);

    $task = makeTaskForExecutionTest($provider->id);
    $task->update([
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(10),
    ]);

    $completedTask = app(CompleteTask::class)->forStaff(
        $task->id,
        $staffUser->id,
    );

    expect($completedTask->status)->toBe('completed')
        ->and($completedTask->completed_by_user_id)->toBe($staffUser->id)
        ->and($completedTask->completed_at)->not->toBeNull();
})->with(['admin', 'supervisor']);

it('completes an in-progress task through the staff endpoint', function (string $role) {
    $staffUser = userWithRole($role);
    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000210',
    ]);

    $task = makeTaskForExecutionTest($provider->id);
    $task->update([
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(5),
    ]);

    authenticateApiUser($staffUser);

    $this->postJson("/api/tasks/{$task->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed_by', $staffUser->name);

    expect($task->fresh()->completed_by_user_id)->toBe($staffUser->id)
        ->and($task->fresh()->completed_at)->not->toBeNull();
})->with(['admin', 'supervisor']);

it('does not allow staff to complete a pending task', function () {
    $staffUser = userWithRole('admin');

    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000206',
    ]);

    $task = makeTaskForExecutionTest($provider->id);

    expect(fn () => app(CompleteTask::class)->forStaff(
        $task->id,
        $staffUser->id,
    ))->toThrow(ValidationException::class);

    expect($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->completed_at)->toBeNull()
        ->and($task->fresh()->completed_by_user_id)->toBeNull();
});

it('returns the name of the staff member who completed the task', function () {
    $staffUser = userWithRole('admin');

    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000207',
    ]);

    $task = makeTaskForExecutionTest($provider->id);
    $task->update([
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(10),
    ]);

    app(CompleteTask::class)->forStaff($task->id, $staffUser->id);

    authenticateApiUser($staffUser);
    $this->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed_by', $staffUser->name);
});

it('allows the assigned provider to update and clear notes while the task is in progress', function () {
    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000208',
    ]);

    $task = makeTaskForExecutionTest($provider->id);
    $task->update([
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    authenticateApiUser($providerUser);

    $this->patchJson("/api/my/tasks/{$task->id}/notes", [
        'provider_notes' => 'Apartamento muito sujo; um copo quebrado.',
    ])
        ->assertOk()
        ->assertJsonPath('data.provider_notes', 'Apartamento muito sujo; um copo quebrado.');

    expect($task->fresh()->provider_notes)
        ->toBe('Apartamento muito sujo; um copo quebrado.');

    $this->patchJson("/api/my/tasks/{$task->id}/notes", [
        'provider_notes' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.provider_notes', null);

    expect($task->fresh()->provider_notes)->toBeNull();
});