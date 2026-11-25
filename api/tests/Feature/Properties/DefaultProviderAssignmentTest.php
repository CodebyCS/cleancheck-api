<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\TaskAssignmentHistory;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function defaultProviderProperty(?ServiceProvider $default): Property
{
    return Property::create([
        'name' => 'Casa Prestador Padrão',
        'address' => 'Rua Fictícia 70',
        'postal_code' => '4050-123',
        'city' => 'Porto',
        'responsible_service_provider_id' => $default?->id,
    ]);
}

/** Future booking, so both the checkout cleaning and the inspection can be created. */
function defaultProviderBooking(Property $property): Booking
{
    $source = BookingSource::create([
        'property_id' => $property->id,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    return Booking::create([
        'booking_source_id' => $source->id,
        'external_uid' => (string) Str::uuid(),
        'source_check_in_at' => now()->addDays(3)->toDateString(),
        'source_check_out_at' => now()->addDays(5)->toDateString(),
        'status' => ReservationStatus::Reserved,
    ]);
}

function defaultProviderCleaning(Property $property): Task
{
    return app(CreateCheckoutCleaningTask::class)->execute(defaultProviderBooking($property)->id);
}

it('assigns a new checkout cleaning to the default provider of the property', function () {
    $default = createServiceProvider('padrao@example.test');
    createServiceProvider('outro@example.test');

    $task = defaultProviderCleaning(defaultProviderProperty($default));

    expect($task->service_provider_id)->toBe($default->id)
        ->and($task->assignmentHistory()->count())->toBe(1)
        ->and($task->assignmentHistory()->first()->new_service_provider_id)->toBe($default->id)
        ->and($task->assignmentHistory()->first()->user_id)->toBeNull();
});

it('assigns a new inspection created by staff to the default provider', function () {
    $default = createServiceProvider('padrao@example.test');
    $booking = defaultProviderBooking(defaultProviderProperty($default));
    $actor = userWithRole('supervisor');
    authenticateApiUser($actor);

    $this->postJson('/api/tasks', ['booking_id' => $booking->id])
        ->assertCreated()
        ->assertJsonPath('data.service_provider_id', $default->id);

    $task = Task::query()->where('booking_id', $booking->id)->firstOrFail();
    $history = $task->assignmentHistory()->sole();
    expect($history->new_service_provider_id)->toBe($default->id)
        ->and($history->user_id)->toBe($actor->id);
});

it('keeps a provider that was already chosen for the task', function () {
    $default = createServiceProvider('padrao@example.test');
    $chosen = createServiceProvider('escolhido@example.test');
    $property = defaultProviderProperty($default);

    $task = Task::factory()->create(['property_id' => $property->id, 'service_provider_id' => $chosen->id]);

    expect($task->service_provider_id)->toBe($chosen->id);
});

it('leaves the task unassigned when the default provider is inactive, without picking another', function () {
    $default = createServiceProvider('inativo@example.test');
    $default->user->forceFill(['is_active' => false])->save();
    createServiceProvider('disponivel@example.test');
    $property = defaultProviderProperty($default);

    $task = defaultProviderCleaning($property);

    expect($task->service_provider_id)->toBeNull()
        ->and($task->assignmentHistory()->count())->toBe(0);
    $this->assertDatabaseCount('booking_notices', 0);

    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/tasks/assignment-warnings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.task_id', $task->id)
        ->assertJsonPath('data.0.default_service_provider_id', $default->id)
        ->assertJsonPath('data.0.warnings.0.code', 'default_provider_inactive');
});

it('leaves the task unassigned when the property has no default provider', function () {
    createServiceProvider('disponivel@example.test');

    $task = defaultProviderCleaning(defaultProviderProperty(null));

    expect($task->service_provider_id)->toBeNull()
        ->and($task->assignmentHistory()->count())->toBe(0);
    $this->assertDatabaseCount('booking_notices', 0);

    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/tasks/assignment-warnings')
        ->assertOk()
        ->assertJsonPath('data.0.task_id', $task->id)
        ->assertJsonPath('data.0.warnings.0.code', 'no_default_provider');
});

it('applies a new default provider only to future tasks', function () {
    $first = createServiceProvider('primeiro@example.test');
    $second = createServiceProvider('segundo@example.test');
    $property = defaultProviderProperty($first);
    $existing = defaultProviderCleaning($property);
    authenticateApiUser(userWithRole('admin'));

    $this->putJson("/api/properties/{$property->id}", [
        'name' => $property->name,
        'address' => $property->address,
        'postal_code' => $property->postal_code,
        'city' => $property->city,
        'responsible_service_provider_id' => $second->id,
    ])->assertOk();

    $future = defaultProviderCleaning($property->fresh());

    expect($existing->fresh()->service_provider_id)->toBe($first->id)
        ->and($future->service_provider_id)->toBe($second->id)
        ->and(TaskAssignmentHistory::count())->toBe(2);
});

it('lets admin and supervisor change the provider of a task and records it', function (string $role) {
    $default = createServiceProvider('padrao@example.test');
    $other = createServiceProvider('outro@example.test');
    $task = defaultProviderCleaning(defaultProviderProperty($default));
    $user = authenticateApiUser(userWithRole($role));

    $this->patchJson("/api/tasks/{$task->id}", ['service_provider_id' => $other->id])
        ->assertOk()
        ->assertJsonPath('data.service_provider_id', $other->id);

    $history = TaskAssignmentHistory::where('task_id', $task->id)
        ->whereNotNull('user_id')
        ->sole();

    expect(TaskAssignmentHistory::where('task_id', $task->id)->count())->toBe(2)
        ->and($history->previous_service_provider_id)->toBe($default->id)
        ->and($history->new_service_provider_id)->toBe($other->id)
        ->and($history->user_id)->toBe($user->id);
})->with(['admin', 'supervisor']);

it('forbids a service provider from changing the provider of a task', function () {
    $default = createServiceProvider('padrao@example.test');
    $other = createServiceProvider('outro@example.test');
    $task = defaultProviderCleaning(defaultProviderProperty($default));
    authenticateApiUser($default->user);

    $this->patchJson("/api/tasks/{$task->id}", ['service_provider_id' => $other->id])->assertForbidden();

    expect($task->fresh()->service_provider_id)->toBe($default->id)
        ->and(TaskAssignmentHistory::where('task_id', $task->id)->count())->toBe(1)
        ->and(TaskAssignmentHistory::where('task_id', $task->id)->first()->user_id)->toBeNull();
});

it('flags inactive providers, schedule conflicts and unassigned tasks', function () {
    $busy = createServiceProvider('ocupado@example.test');
    $leaving = createServiceProvider('sai@example.test');
    $property = defaultProviderProperty(null);
    $window = ['scheduled_start_at' => '2030-01-07 11:30:00', 'scheduled_end_at' => '2030-01-07 14:30:00'];

    $first = Task::factory()->create(['property_id' => $property->id, 'service_provider_id' => $busy->id, ...$window]);
    $second = Task::factory()->create(['property_id' => $property->id, 'service_provider_id' => $busy->id, ...$window]);
    $inactive = Task::factory()->create(['property_id' => $property->id, 'service_provider_id' => $leaving->id]);
    $leaving->user->forceFill(['is_active' => false])->save();
    $unassigned = Task::factory()->create(['property_id' => $property->id, 'service_provider_id' => null]);
    // The property gets an active default after the task was created.
    $property->update(['responsible_service_provider_id' => $busy->id]);
    Task::factory()->create(['property_id' => $property->id, 'service_provider_id' => $busy->id, 'status' => 'completed', ...$window]);

    authenticateApiUser(userWithRole('admin'));

    $warnings = collect($this->getJson("/api/tasks/assignment-warnings?property_id={$property->id}")
        ->assertOk()
        ->json('data'))
        ->keyBy('task_id');

    expect($warnings)->toHaveCount(4)
        ->and($warnings[$first->id]['warnings'])->toBe([['code' => 'schedule_conflict', 'conflicting_task_ids' => [$second->id]]])
        ->and($warnings[$second->id]['warnings'][0]['conflicting_task_ids'])->toBe([$first->id])
        ->and($warnings[$inactive->id]['warnings'][0]['code'])->toBe('provider_inactive')
        ->and($warnings[$unassigned->id]['warnings'][0]['code'])->toBe('unassigned');
});

it('forbids service providers from listing assignment warnings', function () {
    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson('/api/tasks/assignment-warnings')->assertForbidden();
});
