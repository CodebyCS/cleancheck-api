<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('restricts provider list and detail to assigned tasks and supports filters and pagination', function () {
    $providerUser = userWithRole('service_provider');
    $provider = $providerUser->serviceProvider()->create([
        'phone' => '910000101',
    ]);

    $otherProviderUser = userWithRole('service_provider');
    $otherProvider = $otherProviderUser->serviceProvider()->create([
        'phone' => '910000102',
    ]);

    $createTask = function (
        int $serviceProviderId,
        string $externalUid,
        string $type = 'checkout_cleaning',
        string $status = 'pending',
    ): Task {
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
            'service_provider_id' => $serviceProviderId,
            'type' => $type,
            'status' => $status,
            'scheduled_start_at' => $now->copy()->addDay(),
        ]);
    };

    $myTask = $createTask($provider->id, 'provider-list-mine-1');
    $secondMatchingTask = $createTask($provider->id, 'provider-list-mine-2');
    $otherTask = $createTask($otherProvider->id, 'provider-list-other');

    $myTask->update([
        'scheduled_start_at' => now()->addDays(3),
        'scheduled_end_at' => now()->addDays(3)->addHour(),
    ]);
    $secondMatchingTask->update([
        'scheduled_start_at' => now()->addDays(2),
        'scheduled_end_at' => now()->addDays(2)->addHour(),
    ]);

    $filteredOutTask = $createTask(
        $provider->id,
        'provider-list-filtered-out',
        'checkin_inspection',
        'completed',
    );
    $filteredOutTask->update(['scheduled_start_at' => now()->addDays(4)]);

    authenticateApiUser($providerUser);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [[
            'id',
            'property',
            'property_address',
            'access_instructions',
            'type',
            'scheduled_start_at',
            'scheduled_end_at',
            'is_overdue',
            'status',
            'has_video',
            'missing_evidence',
            'started_at',
            'completed_at',
            'provider_notes',
        ]]])
        ->assertJsonPath('data.0.id', $secondMatchingTask->id)
        ->assertJsonPath('data.0.property', 'Casa de teste provider-list-mine-2')
        ->assertJsonPath('data.0.property_address', 'Rua de teste, 1')
        ->assertJsonPath('data.0.type', 'checkout_cleaning')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonMissing(['id' => $otherTask->id]);

    $orderedTasks = $this->getJson('/api/my/tasks')->assertOk()->json('data');
    expect(\Illuminate\Support\Carbon::parse($orderedTasks[0]['scheduled_start_at']))
        ->toEqual($secondMatchingTask->fresh()->scheduled_start_at)
        ->and(\Illuminate\Support\Carbon::parse($orderedTasks[1]['scheduled_start_at']))
        ->toEqual($myTask->fresh()->scheduled_start_at);

    $this->getJson("/api/my/tasks/{$myTask->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $myTask->id);

    $this->getJson("/api/my/tasks/{$otherTask->id}")
        ->assertNotFound();

    $this->getJson('/api/my/tasks?type=checkout_cleaning&status=pending&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $secondMatchingTask->id)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 1);

    $this->getJson('/api/my/tasks?type=checkout_cleaning&status=pending&per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $myTask->id)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 2);

    $this->getJson('/api/my/tasks?deleted=only')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deleted');
});
