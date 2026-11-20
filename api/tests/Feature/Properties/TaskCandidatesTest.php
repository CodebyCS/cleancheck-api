<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use App\Modules\Properties\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// 2030-01-07 is a Monday; Lisbon is UTC in January.
const CANDIDATE_TASK_START = '2030-01-07 11:30:00';
const CANDIDATE_TASK_END = '2030-01-07 14:30:00';

function candidateProperty(?Zone $zone, string $timezone = 'Europe/Lisbon'): Property
{
    return Property::create([
        'name' => 'Casa Candidatos',
        'address' => 'Rua Fictícia 30',
        'postal_code' => '4050-123',
        'city' => 'Porto',
        'zone_id' => $zone?->id,
        'timezone' => $timezone,
    ]);
}

function candidateTask(Property $property, array $overrides = []): Task
{
    return Task::factory()->create(array_merge([
        'property_id' => $property->id,
        'service_provider_id' => null,
        'scheduled_start_at' => CANDIDATE_TASK_START,
        'scheduled_end_at' => CANDIDATE_TASK_END,
    ], $overrides));
}

/** Provider working on Mondays from 09:00 to 18:00, optionally in a zone. */
function candidateProvider(string $email, ?Zone $zone = null, array $attributes = []): ServiceProvider
{
    $provider = createServiceProvider($email, $attributes);
    $provider->availabilities()->create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '18:00']);

    if ($zone) {
        $provider->zones()->attach($zone);
    }

    return $provider;
}

/** @return array<string, mixed>|null */
function candidateFor(array $candidates, ServiceProvider $provider): ?array
{
    return collect($candidates)->firstWhere('service_provider_id', $provider->id);
}

it('lists an available provider in the zone as eligible', function () {
    $zone = Zone::create(['name' => 'Porto']);
    $task = candidateTask(candidateProperty($zone));
    $provider = candidateProvider('apto@example.test', $zone);
    authenticateApiUser(userWithRole('supervisor'));

    $this->getJson("/api/tasks/{$task->id}/candidates")
        ->assertOk()
        ->assertJsonPath('data.zone_id', $zone->id)
        ->assertJsonPath('data.window.weekday', 1)
        ->assertJsonPath('data.window.local_start', '11:30')
        ->assertJsonPath('data.window.local_end', '14:30')
        ->assertJsonPath('data.candidates.0.service_provider_id', $provider->id)
        ->assertJsonPath('data.candidates.0.eligible', true)
        ->assertJsonPath('data.candidates.0.in_zone', true)
        ->assertJsonPath('data.candidates.0.reasons', []);
});

it('explains why each provider is not eligible', function () {
    $zone = Zone::create(['name' => 'Porto']);
    $otherZone = Zone::create(['name' => 'Braga']);
    $property = candidateProperty($zone);
    $task = candidateTask($property);

    $eligible = candidateProvider('apto@example.test', $zone);
    $outsideZone = candidateProvider('longe@example.test', $otherZone);
    $noSchedule = createServiceProvider('semhorario@example.test');
    $noSchedule->zones()->attach($zone);
    $outsideHours = createServiceProvider('tarde@example.test');
    $outsideHours->zones()->attach($zone);
    $outsideHours->availabilities()->create(['weekday' => 1, 'start_time' => '15:00', 'end_time' => '20:00']);
    $unavailable = candidateProvider('ferias@example.test', $zone);
    $unavailable->unavailabilities()->create([
        'starts_at' => '2030-01-07 00:00:00',
        'ends_at' => '2030-01-08 00:00:00',
        'reason' => 'Férias',
    ]);
    $busy = candidateProvider('ocupado@example.test', $zone);
    $otherTask = candidateTask($property, [
        'service_provider_id' => $busy->id,
        'scheduled_start_at' => '2030-01-07 13:00:00',
        'scheduled_end_at' => '2030-01-07 16:00:00',
    ]);
    $inactive = candidateProvider('inativo@example.test', $zone);
    $inactive->user->update(['is_active' => false]);

    authenticateApiUser(userWithRole('admin'));
    $candidates = $this->getJson("/api/tasks/{$task->id}/candidates")
        ->assertOk()
        ->json('data.candidates');

    expect($candidates)->toHaveCount(6)
        ->and($candidates[0]['service_provider_id'])->toBe($eligible->id)
        ->and(candidateFor($candidates, $outsideZone)['reasons'])->toBe(['outside_zone'])
        ->and(candidateFor($candidates, $outsideZone)['in_zone'])->toBeFalse()
        ->and(candidateFor($candidates, $noSchedule)['reasons'])->toBe(['no_schedule'])
        ->and(candidateFor($candidates, $outsideHours)['reasons'])->toBe(['outside_hours'])
        ->and(candidateFor($candidates, $unavailable)['reasons'])->toBe(['unavailable'])
        ->and(candidateFor($candidates, $unavailable)['unavailability']['reason'])->toBe('Férias')
        ->and(candidateFor($candidates, $busy)['reasons'])->toBe(['busy'])
        ->and(candidateFor($candidates, $busy)['conflicting_task_ids'])->toBe([$otherTask->id])
        ->and(candidateFor($candidates, $inactive))->toBeNull();
});

it('ignores finished tasks and the task itself when checking conflicts', function () {
    $property = candidateProperty(null);
    $provider = candidateProvider('livre@example.test');
    $task = candidateTask($property, ['service_provider_id' => $provider->id]);
    candidateTask($property, [
        'service_provider_id' => $provider->id,
        'status' => 'completed',
    ]);
    authenticateApiUser(userWithRole('admin'));

    $this->getJson("/api/tasks/{$task->id}/candidates")
        ->assertOk()
        ->assertJsonPath('data.candidates.0.eligible', true)
        ->assertJsonPath('data.candidates.0.is_assigned', true)
        ->assertJsonPath('data.candidates.0.in_zone', null);
});

it('reads the weekly hours in the property timezone', function () {
    // 14:30-17:30 UTC is 11:30-14:30 in São Paulo (UTC-3, no daylight saving).
    $task = candidateTask(candidateProperty(null, 'America/Sao_Paulo'), [
        'scheduled_start_at' => '2030-01-07 14:30:00',
        'scheduled_end_at' => '2030-01-07 17:30:00',
    ]);
    $provider = createServiceProvider('brasil@example.test');
    $provider->availabilities()->create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '15:00']);
    authenticateApiUser(userWithRole('admin'));

    $this->getJson("/api/tasks/{$task->id}/candidates")
        ->assertOk()
        ->assertJsonPath('data.window.timezone', 'America/Sao_Paulo')
        ->assertJsonPath('data.window.local_start', '11:30')
        ->assertJsonPath('data.candidates.0.eligible', true);
});

it('rejects finished tasks and tasks without a window', function () {
    $property = candidateProperty(null);
    $completed = candidateTask($property, ['status' => 'completed']);
    $withoutEnd = candidateTask($property, ['scheduled_end_at' => null]);
    authenticateApiUser(userWithRole('admin'));

    $this->getJson("/api/tasks/{$completed->id}/candidates")->assertUnprocessable();
    $this->getJson("/api/tasks/{$withoutEnd->id}/candidates")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A tarefa não tem horário definido.');
    $this->getJson('/api/tasks/999999/candidates')->assertNotFound();
});

it('forbids service providers from listing candidates', function () {
    $task = candidateTask(candidateProperty(null));
    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson("/api/tasks/{$task->id}/candidates")->assertForbidden();
});
