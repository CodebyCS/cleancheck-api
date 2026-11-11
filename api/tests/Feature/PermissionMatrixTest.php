<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function permissionMatrixTask(?int $providerId = null): Task
{
    $property = icalProperty();

    $sourceId = DB::table('booking_sources')->insertGetId([
        'property_id' => $property->id,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    $bookingId = DB::table('bookings')->insertGetId([
        'booking_source_id' => $sourceId,
        'external_uid' => 'permission-test',
        'source_check_in_at' => now(),
        'source_check_out_at' => now()->addDay(),
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Task::create([
        'booking_id' => $bookingId,
        'property_id' => $property->id,
        'service_provider_id' => $providerId,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => now(),
    ]);
}

it('applies role permissions when listing management records', function (
    string $role,
    string $endpoint,
    int $expectedStatus,
){
    authenticateApiUser(userWithRole($role));

    $this->getJson($endpoint)->assertStatus($expectedStatus);
})->with([
    ['admin', '/api/properties', 200],
    ['supervisor', '/api/properties', 200],
    ['service_provider', '/api/properties', 403],
    ['admin', '/api/service-providers', 200],
    ['supervisor', '/api/service-providers', 200],
    ['service_provider', '/api/service-providers', 403],
]);

it('allows each supported role to query its task scope', function (
    string $role,
) {
    authenticateApiUser(userWithRole($role));

    $this->getJson('/api/tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->with(['admin', 'supervisor']);

it('limits the provider to its own task routes', function () {
    $provider = createServiceProvider('provider-routes@example.test');

    authenticateApiUser($provider->user);

    $this->getJson('/api/tasks')->assertForbidden();

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('checks authorization before validating an ical upload', function (
    string $role,
    string $endpoint,
    int $expectedStatus,
) {
    authenticateApiUser(userWithRole($role));

    $this->postJson($endpoint, [])
        ->assertStatus($expectedStatus);
})->with([
    ['admin', '/api/reservations/ical/read', 422],
    ['supervisor', '/api/reservations/ical/read', 422],
    ['service_provider', '/api/reservations/ical/read', 403],
    ['admin', '/api/reservations/ical/import', 422],
    ['supervisor', '/api/reservations/ical/import', 422],
    ['service_provider', '/api/reservations/ical/import', 403],
]);

it('rejects unauthenticated requests to protected lists', function (
    string $endpoint,
) {
    $this->getJson($endpoint)->assertUnauthorized();
})->with([
    '/api/properties',
    '/api/service-providers',
    '/api/tasks',
]);

it('restricts providers to their assigned tasks', function () {
    $ana = createServiceProvider('ana-permissions@example.test');
    $bruno = createServiceProvider('bruno-permissions@example.test');

    $ownTask = permissionMatrixTask($ana->id);
    $otherTask = permissionMatrixTask($bruno->id);

    authenticateApiUser($ana->user);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownTask->id);

    $this->getJson("/api/my/tasks/{$ownTask->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $ownTask->id);

    $this->getJson("/api/my/tasks/{$otherTask->id}")
        ->assertNotFound();
});

it('allows management roles to assign tasks', function (string $role) {
    $provider = createServiceProvider('assignment-permissions@example.test');
    $task = permissionMatrixTask();

    authenticateApiUser(userWithRole($role));

    $this->patchJson("/api/tasks/{$task->id}", [
        'service_provider_id' => $provider->id,
    ])->assertOk();

    expect($task->fresh()->service_provider_id)->toBe($provider->id);
})->with(['admin', 'supervisor']);

it('prevents providers from changing tasks', function () {
    $provider = createServiceProvider('restricted-permissions@example.test');
    $task = permissionMatrixTask($provider->id);

    authenticateApiUser($provider->user);

    $this->postJson('/api/tasks', [])
        ->assertForbidden();

    $this->patchJson("/api/tasks/{$task->id}", [
        'notes' => 'Unauthorized change',
    ])->assertForbidden();

    $this->deleteJson("/api/tasks/{$task->id}")
        ->assertForbidden();

    $this->postJson("/api/tasks/{$task->id}/restore")
        ->assertForbidden();

    expect($task->fresh()->notes)->toBeNull()
        ->and($task->fresh()->deleted_at)->toBeNull();
});

it('checks role permissions before validating record creation', function (
    string $role,
    string $endpoint,
    int $expectedStatus,
) {
    authenticateApiUser(userWithRole($role));

    $this->postJson($endpoint, [])
        ->assertStatus($expectedStatus);
})->with([
    ['admin', '/api/properties', 422],
    ['supervisor', '/api/properties', 422],
    ['service_provider', '/api/properties', 403],
    ['admin', '/api/service-providers', 422],
    ['supervisor', '/api/service-providers', 422],
    ['service_provider', '/api/service-providers', 403],
]);

it('prevents providers from editing or changing the state of records', function () {
    $property = icalProperty();
    $provider = createServiceProvider('record-owner@example.test');
    $otherProvider = createServiceProvider('other-record@example.test');

    authenticateApiUser($provider->user);

    $this->putJson("/api/properties/{$property->id}", [])
        ->assertForbidden();

    $this->patchJson("/api/properties/{$property->id}/activate")
        ->assertForbidden();

    $this->patchJson("/api/properties/{$property->id}/deactivate")
        ->assertForbidden();

    $this->putJson("/api/service-providers/{$otherProvider->id}", [])
        ->assertForbidden();

    $this->patchJson("/api/service-providers/{$otherProvider->id}/activate")
        ->assertForbidden();

    $this->patchJson("/api/service-providers/{$otherProvider->id}/deactivate")
        ->assertForbidden();

    $this->postJson("/api/service-providers/{$otherProvider->id}/photo", [])
        ->assertForbidden();

    expect($provider->user->fresh()->is_active)->toBeTrue()
        ->and($otherProvider->user->fresh()->is_active)->toBeTrue();
});