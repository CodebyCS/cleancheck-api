<?php

use App\Models\Task;
use App\Models\TaskAssignmentHistory;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function assignmentTask(string $status = 'pending', ?int $providerId = null): Task
{
    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Casa',
        'address' => 'Rua de teste',
        'postal_code' => '1000-001',
        'city' => 'Lisboa',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sourceId = DB::table('booking_sources')->insertGetId([
        'property_id' => $propertyId,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    $bookingId = DB::table('bookings')->insertGetId([
        'booking_source_id' => $sourceId,
        'external_uid' => 'test',
        'source_check_in_at' => now(),
        'source_check_out_at' => now()->addDay(),
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Task::create([
        'booking_id' => $bookingId,
        'property_id' => $propertyId,
        'service_provider_id' => $providerId,
        'type' => 'checkout_cleaning',
        'status' => $status,
        'scheduled_start_at' => now(),
    ]);
}

function assignmentProvider(bool $active = true): ServiceProvider
{
    $user = userWithRole('service_provider');
    $user->is_active = $active;
    $user->save();

    return $user->serviceProvider()->create([
        'phone' => '912345678',
    ]);
}

it('allows an administrator or supervisor to assign an active provider to a pending task', function (string $role) {
    $provider = assignmentProvider();
    $task = assignmentTask();

    authenticateApiUser(userWithRole($role));

    $response = $this->patchJson("/api/tasks/{$task->id}", [
        'service_provider_id' => $provider->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.service_provider_id', $provider->id);

    expect($task->fresh()->serviceProvider->id)->toBe($provider->id)
        ->and($task->fresh()->property->id)->toBe($task->property_id);
})->with(['admin', 'supervisor']);

it('rejects assignment by a provider or an unauthenticated user', function () {
    $provider = assignmentProvider();
    $task = assignmentTask();

    $this->patchJson("/api/tasks/{$task->id}", [
        'service_provider_id' => $provider->id,
    ])->assertUnauthorized();

    authenticateApiUser(userWithRole('service_provider'));

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $provider->id,
        ])
        ->assertForbidden();

    expect($task->fresh()->service_provider_id)->toBeNull();
});

it('rejects nonexistent or inactive providers without editing other fields', function () {
    $inactive = assignmentProvider(false);
    $task = assignmentTask();

    authenticateApiUser(userWithRole('admin'));

    foreach ([$inactive->id, 999999] as $id) {
        $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $id,
            'notes' => 'changed',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_provider_id');

        expect($task->fresh()->service_provider_id)->toBeNull()
            ->and($task->fresh()->notes)->toBeNull();
    }
});

it('rejects a first assignment when the task is not pending', function (string $status) {
    $provider = assignmentProvider();
    $task = assignmentTask($status);

    authenticateApiUser(userWithRole('admin'));

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $provider->id,
        ])
        ->assertUnprocessable();

    expect($task->fresh()->service_provider_id)->toBeNull();
})->with(['in_progress', 'completed', 'cancelled']);

it('records first assignment and successive reassignments without losing history', function () {
    $old = assignmentProvider();
    $new = assignmentProvider();
    $task = assignmentTask();
    $actor = userWithRole('supervisor');

    authenticateApiUser($actor);

    foreach ([$old->id, $new->id, $old->id] as $id) {
        $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $id,
        ])->assertOk();
    }

    $history = $task->assignmentHistory()->orderBy('id')->get();

    expect($history)->toHaveCount(3)
        ->and($history[0]->previous_service_provider_id)->toBeNull()
        ->and($history[0]->new_service_provider_id)->toBe($old->id)
        ->and($history[1]->previous_service_provider_id)->toBe($old->id)
        ->and($history[1]->new_service_provider_id)->toBe($new->id)
        ->and($history[2]->previous_service_provider_id)->toBe($new->id)
        ->and($history[2]->new_service_provider_id)->toBe($old->id)
        ->and($history[2]->user_id)->toBe($actor->id)
        ->and($history[2]->created_at)->not->toBeNull();
});

it('allows reassignment in non-completed states', function (string $status) {
    $old = assignmentProvider();
    $new = assignmentProvider();
    $task = assignmentTask($status, $old->id);

    authenticateApiUser(userWithRole('admin'));

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $new->id,
        ])
        ->assertOk();

    expect($task->fresh()->service_provider_id)->toBe($new->id)
        ->and($task->assignmentHistory()->count())->toBe(1);
})->with(['pending', 'in_progress', 'cancelled']);

it('rejects reassignment of a completed task and rejects removal', function () {
    $old = assignmentProvider();
    $new = assignmentProvider();
    $task = assignmentTask('completed', $old->id);

    authenticateApiUser(userWithRole('supervisor'));

    foreach ([$new->id, null] as $id) {
        $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $id,
        ])->assertUnprocessable();
    }

    expect($task->fresh()->service_provider_id)->toBe($old->id)
        ->and($task->assignmentHistory()->count())->toBe(0);
});

it('does not add history or change the task when the same provider is sent', function () {
    $provider = assignmentProvider();
    $task = assignmentTask('completed', $provider->id);
    $originalUpdatedAt = $task->updated_at;

    authenticateApiUser(userWithRole('admin'));

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $provider->id,
        ])
        ->assertOk();

    expect($task->assignmentHistory()->count())->toBe(0)
        ->and($task->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

it('rejects removing a provider from a pending task without changing its history', function () {
    $provider = assignmentProvider();
    $task = assignmentTask('pending', $provider->id);

    authenticateApiUser(userWithRole('admin'));

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service_provider_id');

    expect($task->fresh()->service_provider_id)->toBe($provider->id)
        ->and($task->assignmentHistory()->count())->toBe(0);
});

it('rolls back the assignment when history creation fails', function () {
    $old = assignmentProvider();
    $new = assignmentProvider();
    $task = assignmentTask('pending', $old->id);

    TaskAssignmentHistory::creating(
        fn () => throw new RuntimeException('History failure')
    );

    $this->withoutExceptionHandling();
    authenticateApiUser(userWithRole('admin'));

    try {
        $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $new->id,
        ]);

        test()->fail('A falha de histórico devia interromper a operação.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('History failure');
    } finally {
        TaskAssignmentHistory::flushEventListeners();
    }

    expect($task->fresh()->service_provider_id)->toBe($old->id)
        ->and($task->assignmentHistory()->count())->toBe(0);
});

it('changes task access between providers after reassignment and preserves history', function () {
    $a = assignmentProvider();
    $b = assignmentProvider();
    $task = assignmentTask();
    $admin = userWithRole('admin');

    authenticateApiUser($admin);

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $a->id,
        ])
        ->assertOk();

    authenticateApiUser($a->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $task->id);

    $this->getJson("/api/my/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id);

    authenticateApiUser($b->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/my/tasks/{$task->id}")
        ->assertNotFound();

    authenticateApiUser($admin);

    $this->patchJson("/api/tasks/{$task->id}", [
            'service_provider_id' => $b->id,
        ])
        ->assertOk();

    authenticateApiUser($a->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/my/tasks/{$task->id}")
        ->assertNotFound();

    authenticateApiUser($b->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $task->id);

    $this->getJson("/api/my/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id);

    expect($task->assignmentHistory()->count())->toBe(2)
        ->and($task->assignmentHistory()->orderBy('id')->first()->new_service_provider_id)
        ->toBe($a->id);

    authenticateApiUser($admin);

    $this->getJson('/api/tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $task->id);

    $this->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id);
});

it('denies task access without authentication or without a provider profile', function () {
    $task = assignmentTask();

    $this->getJson('/api/tasks')->assertUnauthorized();
    $this->getJson("/api/tasks/{$task->id}")->assertUnauthorized();
    $this->getJson('/api/my/tasks')->assertUnauthorized();
    $this->getJson("/api/my/tasks/{$task->id}")->assertUnauthorized();

    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson('/api/my/tasks')->assertForbidden();
    $this->getJson("/api/my/tasks/{$task->id}")->assertForbidden();
});

it('forbids providers from modifying or deleting tasks through other routes', function () {
    $provider = assignmentProvider();
    $task = assignmentTask('pending', $provider->id);

    authenticateApiUser($provider->user);

    $this->patchJson("/api/tasks/{$task->id}", [
        'notes' => 'changed',
    ])->assertForbidden();

    $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();
    $this->postJson("/api/tasks/{$task->id}/restore")->assertForbidden();
    $this->postJson('/api/tasks', [
        'booking_id' => $task->booking_id,
    ])->assertForbidden();

    expect($task->fresh()->notes)->toBeNull()
        ->and($task->fresh()->deleted_at)->toBeNull();
})->skip('TODO(roles): enable when the role middleware protects these routes.');

it('hides deleted tasks from providers and rejects inactive accounts', function () {
    $provider = assignmentProvider();
    $task = assignmentTask('pending', $provider->id);
    $task->delete();

    authenticateApiUser($provider->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/my/tasks/{$task->id}")
        ->assertNotFound();

    $provider->user->is_active = false;
    $provider->user->save();

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->getJson('/api/my/tasks')->assertUnauthorized();
    $this->getJson("/api/my/tasks/{$task->id}")->assertUnauthorized();
});