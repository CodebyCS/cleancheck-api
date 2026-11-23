<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\Zone;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the demo properties and service providers', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('properties', 2);
    $this->assertDatabaseHas('properties', ['name' => 'Apartamento da Foz']);
    $this->assertDatabaseHas('properties', ['name' => 'Casa de Gaia']);
    $this->assertDatabaseCount('service_providers', 3);
});

it('can run the seeders twice without duplicating records', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('properties', 2);
    $this->assertDatabaseCount('service_providers', 3);
    $this->assertDatabaseCount('zones', 2);
    $this->assertDatabaseCount('service_provider_availabilities', 21);
    $this->assertDatabaseCount('service_provider_unavailabilities', 1);
    expect(Task::where('notes', 'Demo: candidatos')->count())->toBe(2);
});

it('seeds the availability scenarios', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Zone::where('name', 'Gaia')->firstOrFail()->serviceProviders()->count())->toBe(0)
        ->and(User::where('email', 'prestador3@example.test')->firstOrFail()->is_active)->toBeFalse();

    $foz = Property::where('name', 'Apartamento da Foz')->firstOrFail();
    $gaia = Property::where('name', 'Casa de Gaia')->firstOrFail();
    $first = User::where('email', 'prestador1@example.test')->firstOrFail()->serviceProvider;
    $second = User::where('email', 'prestador2@example.test')->firstOrFail()->serviceProvider;
    $fozTask = Task::where('property_id', $foz->id)->where('notes', 'Demo: candidatos')->firstOrFail();
    $gaiaTask = Task::where('property_id', $gaia->id)->where('notes', 'Demo: candidatos')->firstOrFail();

    authenticateApiUser(userWithRole('admin'));

    $fozCandidates = collect($this->getJson("/api/tasks/{$fozTask->id}/candidates")->assertOk()->json('data.candidates'));

    expect($fozCandidates)->toHaveCount(2)
        ->and($fozCandidates->firstWhere('service_provider_id', $first->id)['eligible'])->toBeTrue()
        ->and($fozCandidates->firstWhere('service_provider_id', $second->id)['reasons'])->toBe(['unavailable']);

    $gaiaCandidates = collect($this->getJson("/api/tasks/{$gaiaTask->id}/candidates")->assertOk()->json('data.candidates'));

    expect($gaiaCandidates->where('eligible', true))->toHaveCount(0)
        ->and($gaiaCandidates->firstWhere('service_provider_id', $first->id)['reasons'])->toBe(['outside_zone'])
        ->and($gaiaCandidates->pluck('reasons')->all())->each->toContain('outside_zone');
});
