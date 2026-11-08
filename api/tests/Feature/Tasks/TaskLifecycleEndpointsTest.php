<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Creates a property, a reservation and a checkout cleaning task.
 *
 * $checkOutInDays controls when the reservation ends (negative = already ended).
 * $bookingStatus is the reservation status ('reserved' is the only one the Tasks module accepts).
 */
function makeEndpointTask(array $overrides = [], int $checkOutInDays = 5, string $bookingStatus = 'reserved'): Task
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
        'external_uid' => 'endpoint-test-' . uniqid(),
        'source_check_in_at' => $now->copy()->addDays($checkOutInDays - 2),
        'source_check_out_at' => $now->copy()->addDays($checkOutInDays),
        'status' => $bookingStatus,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Task::create(array_merge([
        'booking_id' => $bookingId,
        'property_id' => $propertyId,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => $now->copy()->addDays($checkOutInDays)->setTime(11, 30),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// POST /api/tasks/{id}/cancel
// ---------------------------------------------------------------------------

it('cancels a pending task through the endpoint', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/cancel")
        ->assertNoContent();

    $cancelled = Task::withTrashed()->findOrFail($task->id);

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->deleted_at)->not->toBeNull();
});

it('treats repeating the cancel request as a success without changing deleted_at', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/cancel")->assertNoContent();
    $deletedAt = Task::withTrashed()->findOrFail($task->id)->deleted_at;

    $this->travel(1)->minutes();

    $this->postJson("/api/tasks/{$task->id}/cancel")->assertNoContent();

    expect(Task::withTrashed()->findOrFail($task->id)->deleted_at->equalTo($deletedAt))->toBeTrue();
});

it('rejects cancelling tasks that are in progress or completed', function (string $status) {
    $task = makeEndpointTask(['status' => $status]);

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($task->fresh()->status)->toBe($status)
        ->and($task->fresh()->deleted_at)->toBeNull();
})->with(['in_progress', 'completed']);

it('returns 404 when cancelling a task that does not exist', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/tasks/999999/cancel')
        ->assertNotFound();
});

it('does not turn a task removed with the delete endpoint into a cancelled one', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('admin'));

    $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    $this->postJson("/api/tasks/{$task->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect(Task::withTrashed()->findOrFail($task->id)->status)->toBe('pending');
});

it('forbids a service provider from cancelling a task', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('service_provider'));

    $this->postJson("/api/tasks/{$task->id}/cancel")
        ->assertForbidden();

    expect($task->fresh()->status)->toBe('pending');
})

// ---------------------------------------------------------------------------
// DELETE /api/tasks/{id}
// ---------------------------------------------------------------------------

it('soft deletes a pending task and keeps its status', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('admin'));

    $this->deleteJson("/api/tasks/{$task->id}")
        ->assertNoContent();

    $deleted = Task::withTrashed()->findOrFail($task->id);

    expect($deleted->status)->toBe('pending')
        ->and($deleted->deleted_at)->not->toBeNull();
});

it('rejects deleting tasks that are in progress or completed', function (string $status) {
    $task = makeEndpointTask(['status' => $status]);

    authenticateApiUser(userWithRole('admin'));

    $this->deleteJson("/api/tasks/{$task->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($task->fresh()->deleted_at)->toBeNull();
})->with(['in_progress', 'completed']);

it('forbids a service provider from deleting or restoring tasks', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('service_provider'));

    $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();
    $this->postJson("/api/tasks/{$task->id}/restore")->assertForbidden();

    expect($task->fresh()->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// POST /api/tasks/{id}/restore
// ---------------------------------------------------------------------------

it('restores a cancelled task as pending with cleared execution timestamps', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/cancel")->assertNoContent();
    $this->postJson("/api/tasks/{$task->id}/restore")->assertOk();

    // The default scope hides deleted tasks, so finding it proves it was restored.
    $restored = Task::findOrFail($task->id);

    expect($restored->status)->toBe('pending')
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->started_at)->toBeNull()
        ->and($restored->completed_at)->toBeNull()
        ->and($restored->scheduled_start_at->format('H:i'))->toBe('11:30');
});

it('returns 404 when restoring a task that is not deleted', function () {
    $task = makeEndpointTask();

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/restore")
        ->assertNotFound();
});

it('refuses to restore a task whose reservation is no longer reserved', function () {
    $task = makeEndpointTask(bookingStatus: 'cancelled');

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/cancel")->assertNoContent();

    $this->postJson("/api/tasks/{$task->id}/restore")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking_id');

    expect(Task::withTrashed()->findOrFail($task->id)->deleted_at)->not->toBeNull();
});

it('refuses to restore a cleaning whose check-out has already passed', function () {
    $task = makeEndpointTask(checkOutInDays: -2);

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/cancel")->assertNoContent();

    $this->postJson("/api/tasks/{$task->id}/restore")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('check_out');

    expect(Task::withTrashed()->findOrFail($task->id)->status)->toBe('cancelled');
});
