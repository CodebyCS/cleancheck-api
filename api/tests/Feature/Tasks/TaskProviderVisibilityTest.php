<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Creates a property, a reservation and a checkout cleaning task for the given provider. */
function makeVisibilityTask(?int $serviceProviderId, array $overrides = []): Task
{
    $now = now();

    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Casa de teste ' . uniqid(),
        'address' => 'Rua de teste, 1',
        'postal_code' => '1000-001',
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
        'external_uid' => 'visibility-test-' . uniqid(),
        'source_check_in_at' => $now->copy()->addDay(),
        'source_check_out_at' => $now->copy()->addDays(2),
        'status' => 'reserved',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Task::create(array_merge([
        'booking_id' => $bookingId,
        'property_id' => $propertyId,
        'service_provider_id' => $serviceProviderId,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => $now->copy()->subHour(),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// What each profile can see
// ---------------------------------------------------------------------------

it('shows the provider only their own tasks and never the amount or the assigned provider', function () {
    $provider = createServiceProvider('visibility-own@example.test');
    $other = createServiceProvider('visibility-other@example.test');

    $mine = makeVisibilityTask($provider->id, ['agreed_amount' => 45.5]);
    makeVisibilityTask($other->id, ['agreed_amount' => 60]);

    authenticateApiUser($provider->user);

    $list = $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $detail = $this->getJson("/api/my/tasks/{$mine->id}")->assertOk();

    foreach (['booking_id', 'service_provider', 'service_provider_id', 'agreed_amount', 'deleted_at'] as $hidden) {
        expect($list->json('data.0'))->not->toHaveKey($hidden)
            ->and($detail->json('data'))->not->toHaveKey($hidden);
    }
});

it('shows the assigned provider and the agreed amount to staff', function () {
    $provider = createServiceProvider('visibility-staff@example.test');
    $task = makeVisibilityTask($provider->id, ['agreed_amount' => 45.5]);

    authenticateApiUser(userWithRole('supervisor'));

    $this->getJson('/api/tasks')
        ->assertOk()
        ->assertJsonPath('data.0.id', $task->id)
        ->assertJsonPath('data.0.service_provider_id', $provider->id)
        ->assertJsonPath('data.0.service_provider', $provider->user->name)
        ->assertJsonPath('data.0.agreed_amount', '45.50');
});

it('rejects the deleted filter on the provider list', function () {
    $provider = createServiceProvider('visibility-filter@example.test');

    authenticateApiUser($provider->user);

    $this->getJson('/api/my/tasks?deleted=only')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deleted');
});

it('hides deleted and cancelled tasks from the provider', function () {
    $provider = createServiceProvider('visibility-deleted@example.test');
    $task = makeVisibilityTask($provider->id);
    $task->update(['status' => 'cancelled']);
    $task->delete();

    authenticateApiUser($provider->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/my/tasks/{$task->id}")->assertNotFound();
});

it('forbids a user without a provider profile from using the provider routes', function () {
    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson('/api/my/tasks')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// is_overdue
// ---------------------------------------------------------------------------

it('flags a task as overdue only when it is open and its window has ended', function (string $status, ?string $end, bool $expected) {
    $task = new Task([
        'status' => $status,
        'scheduled_end_at' => $end === null ? null : now()->modify($end),
    ]);

    expect($task->isOverdue())->toBe($expected);
})->with([
    'pending, window ended' => ['pending', '-1 hour', true],
    'in progress, window ended' => ['in_progress', '-1 hour', true],
    'pending, window not ended' => ['pending', '+1 hour', false],
    'pending, no end date' => ['pending', null, false],
    'completed, window ended' => ['completed', '-1 hour', false],
    'cancelled, window ended' => ['cancelled', '-1 hour', false],
]);

it('exposes is_overdue to providers and staff', function () {
    $provider = createServiceProvider('visibility-overdue@example.test');
    $overdue = makeVisibilityTask($provider->id, [
        'status' => 'in_progress',
        'scheduled_end_at' => now()->subHour(),
    ]);
    $done = makeVisibilityTask($provider->id, [
        'status' => 'completed',
        'scheduled_end_at' => now()->subHour(),
    ]);

    authenticateApiUser($provider->user);
    $this->getJson("/api/my/tasks/{$overdue->id}")->assertOk()->assertJsonPath('data.is_overdue', true);
    $this->getJson("/api/my/tasks/{$done->id}")->assertOk()->assertJsonPath('data.is_overdue', false);

    authenticateApiUser(userWithRole('admin'));
    $this->getJson("/api/tasks/{$overdue->id}")->assertOk()->assertJsonPath('data.is_overdue', true);
    $this->getJson("/api/tasks/{$done->id}")->assertOk()->assertJsonPath('data.is_overdue', false);
});

// ---------------------------------------------------------------------------
// start / complete edge cases
// ---------------------------------------------------------------------------

it('rejects starting a completed task', function () {
    $provider = createServiceProvider('visibility-start-completed@example.test');
    $task = makeVisibilityTask($provider->id, ['status' => 'completed']);

    authenticateApiUser($provider->user);

    $this->postJson("/api/my/tasks/{$task->id}/start")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($task->fresh()->status)->toBe('completed');
});

it('returns 404 when starting or completing a deleted task', function () {
    $provider = createServiceProvider('visibility-start-deleted@example.test');
    $task = makeVisibilityTask($provider->id);
    $task->delete();

    authenticateApiUser($provider->user);

    $this->postJson("/api/my/tasks/{$task->id}/start")->assertNotFound();
    $this->postJson("/api/my/tasks/{$task->id}/complete")->assertNotFound();
});

it('requires authentication to start or complete a task', function () {
    $this->postJson('/api/my/tasks/1/start')->assertUnauthorized();
    $this->postJson('/api/my/tasks/1/complete')->assertUnauthorized();
});
