<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeStaffQueryTask(string $externalUid): Task
{
    $now = now();

    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Casa de teste ' . $externalUid,
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
        'external_uid' => $externalUid,
        'source_check_in_at' => $now->copy()->addDay(),
        'source_check_out_at' => $now->copy()->addDays(2),
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Task::create([
        'booking_id' => $bookingId,
        'property_id' => $propertyId,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => $now->copy()->addDay(),
    ]);
}

it('allows administrators and supervisors to list staff tasks', function (string $role) {
    $task = makeStaffQueryTask('staff-list-' . $role);

    authenticateApiUser(userWithRole($role));

    $this->getJson('/api/tasks')
        ->assertOk()
        ->assertJsonPath('data.0.id', $task->id);
})->with(['admin', 'supervisor']);

it('returns only soft-deleted tasks when staff requests deleted only', function () {
    $activeTask = makeStaffQueryTask('staff-list-active');
    $deletedTask = makeStaffQueryTask('staff-list-deleted');
    $deletedTask->delete();

    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/tasks?deleted=only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deletedTask->id)
        ->assertJsonMissing(['id' => $activeTask->id]);
});

it('forbids a provider from viewing the staff task list and detail', function () {
    $task = makeStaffQueryTask('staff-list-forbidden');

    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson('/api/tasks')
        ->assertForbidden();

    $this->getJson("/api/tasks/{$task->id}")
        ->assertForbidden();
});
