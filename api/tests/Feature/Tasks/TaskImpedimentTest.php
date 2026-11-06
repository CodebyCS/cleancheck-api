<?php

use App\Models\Task;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function impedimentTask(?int $providerId = null, string $status = 'pending'): Task
{
    $property = DB::table('properties')->insertGetId([
        'name' => 'Casa', 'address' => 'Rua', 'postal_code' => '1000-001',
        'city' => 'Lisboa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $source = DB::table('booking_sources')->insertGetId([
        'property_id' => $property, 'channel' => 'test', 'type' => 'url', 'is_active' => true,
    ]);
    $booking = DB::table('bookings')->insertGetId([
        'booking_source_id' => $source, 'external_uid' => 'impediment-test',
        'source_check_in_at' => now(), 'source_check_out_at' => now()->addDay(),
        'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    return Task::create([
        'booking_id' => $booking, 'property_id' => $property, 'service_provider_id' => $providerId,
        'type' => 'checkout_cleaning', 'status' => $status, 'scheduled_start_at' => now(),
    ]);
}

function impedimentProvider(): ServiceProvider
{
    $user = userWithRole('service_provider');
    $user->update(['is_active' => true]);
    return $user->serviceProvider()->create(['phone' => '912345678']);
}

function impedimentBody(string $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', array $overrides = []): array
{
    return array_merge(['reason' => 'equipment_failure', 'description' => 'Equipamento avariado.', 'idempotency_key' => $key], $overrides);
}

it('creates an impediment for the assigned provider without changing task or assignment history', function () {
    $provider = impedimentProvider();
    $task = impedimentTask($provider->id);
    authenticateApiUser($provider->user);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody())
        ->assertCreated()->assertJsonPath('task_id', $task->id)->assertJsonPath('user_id', $provider->user_id);
    expect($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->service_provider_id)->toBe($provider->id)
        ->and($task->assignmentHistory()->count())->toBe(0)
        ->and($task->impediments()->count())->toBe(1);
});

it('returns 401, 403, 404 and 422 for unauthenticated, unauthorized, missing and invalid requests', function () {
    $assigned = impedimentProvider();
    $other = impedimentProvider();
    $task = impedimentTask($assigned->id);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody())->assertUnauthorized();
    authenticateApiUser($other->user);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody())->assertForbidden();
    authenticateApiUser($assigned->user);
    $this->postJson('/api/tasks/999999/impediments', impedimentBody())->assertNotFound();
    $this->postJson("/api/tasks/{$task->id}/impediments", ['description' => str_repeat('x', 2001), 'idempotency_key' => 'bad'])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason', 'description', 'idempotency_key']);
    expect($task->impediments()->count())->toBe(0);
});

it('makes retries idempotent and allows later impediments using a new key', function () {
    $provider = impedimentProvider();
    $task = impedimentTask($provider->id);
    authenticateApiUser($provider->user);
    $body = impedimentBody();
    $first = $this->postJson("/api/tasks/{$task->id}/impediments", $body)->assertCreated();
    $this->postJson("/api/tasks/{$task->id}/impediments", $body)->assertOk()->assertJsonPath('id', $first->json('id'));
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody($body['idempotency_key'], ['reason' => 'different']))
        ->assertStatus(409);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'))->assertCreated();
    expect($task->impediments()->count())->toBe(2);
});

it('revokes the old provider access after reassignment and preserves prior impediment authorship', function () {
    $a = impedimentProvider();
    $b = impedimentProvider();
    $task = impedimentTask($a->id);
    authenticateApiUser($a->user);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody())->assertCreated();
    $this->getJson('/api/tasks')->assertOk()->assertJsonCount(1);
    $this->getJson("/api/tasks/{$task->id}")->assertOk()->assertJsonPath('id', $task->id);
    authenticateApiUser(userWithRole('admin'));
    $this->patchJson("/api/tasks/{$task->id}", ['service_provider_id' => $b->id])->assertOk();
    authenticateApiUser($a->user);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody('cccccccc-cccc-4ccc-8ccc-cccccccccccc'))->assertForbidden();
    $this->getJson('/api/tasks')->assertOk()->assertExactJson([]);
    $this->getJson("/api/tasks/{$task->id}")->assertNotFound();
    authenticateApiUser($b->user);
    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody('dddddddd-dddd-4ddd-8ddd-dddddddddddd'))->assertCreated();
    $this->getJson('/api/tasks')->assertOk()->assertJsonCount(1);
    $this->getJson("/api/tasks/{$task->id}")->assertOk()->assertJsonPath('id', $task->id);
    $records = $task->impediments()->orderBy('id')->get();
    expect($records)->toHaveCount(2)->and($records[0]->user_id)->toBe($a->user_id)
        ->and($records[1]->user_id)->toBe($b->user_id)->and($task->assignmentHistory()->count())->toBe(1);
});

it('returns 401 when the assigned provider account is inactive', function () {
    $provider = impedimentProvider();
    $task = impedimentTask($provider->id);
    authenticateApiUser($provider->user);
    $provider->user->is_active = false;
    $provider->user->save();

    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody())->assertUnauthorized();
    expect($task->impediments()->count())->toBe(0);
});

it('currently accepts impediments on completed and cancelled tasks while assigned', function (string $status) {
    $provider = impedimentProvider();
    $task = impedimentTask($provider->id, $status);
    authenticateApiUser($provider->user);

    $this->postJson("/api/tasks/{$task->id}/impediments", impedimentBody())->assertCreated();
    expect($task->fresh()->status)->toBe($status)
        ->and($task->impediments()->count())->toBe(1);
})->with(['completed', 'cancelled']);

it('keeps the existing empty tasks response as an array', function () {
    $provider = impedimentProvider();
    authenticateApiUser($provider->user);
    $this->getJson('/api/tasks')->assertOk()->assertExactJson([]);
});
