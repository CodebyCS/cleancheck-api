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

    $createTask(
        $provider->id,
        'provider-list-filtered-out',
        'checkin_inspection',
        'completed',
    );

    authenticateApiUser($providerUser);

    $this->getJson('/api/my/tasks')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $myTask->id)
        ->assertJsonMissing(['id' => $otherTask->id]);

    $this->getJson("/api/my/tasks/{$myTask->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $myTask->id);

    $this->getJson("/api/my/tasks/{$otherTask->id}")
        ->assertNotFound();

    $this->getJson('/api/my/tasks?type=checkout_cleaning&status=pending&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $myTask->id)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 1);

    $this->getJson('/api/my/tasks?type=checkout_cleaning&status=pending&per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $secondMatchingTask->id)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 2);

    $this->getJson('/api/my/tasks?deleted=only')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deleted');
});