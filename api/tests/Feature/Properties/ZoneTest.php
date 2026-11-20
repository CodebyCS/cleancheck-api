<?php

declare(strict_types=1);

use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ZONES_URL = '/api/zones';

function zonePropertyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Casa das Zonas',
        'address' => 'Rua Fictícia 20',
        'postal_code' => '4050-123',
        'city' => 'Porto',
    ], $overrides);
}

it('creates zones and lists them with counts', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->postJson(ZONES_URL, ['name' => 'Porto Centro', 'description' => 'Baixa e Ribeira'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Porto Centro')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.properties_count', 0)
        ->assertJsonPath('data.service_providers_count', 0);

    $zone = Zone::where('name', 'Porto Centro')->firstOrFail();
    Property::create(zonePropertyPayload(['zone_id' => $zone->id]));

    $this->getJson(ZONES_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.properties_count', 1);
});

it('allows a supervisor to manage zones', function () {
    authenticateApiUser(userWithRole('supervisor'));

    $this->postJson(ZONES_URL, ['name' => 'Gaia'])->assertCreated();
});

it('rejects a duplicate name but keeps the name on update', function () {
    $zone = Zone::create(['name' => 'Matosinhos']);
    authenticateApiUser(userWithRole('admin'));

    $this->postJson(ZONES_URL, ['name' => 'Matosinhos'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'Já existe uma zona com este nome.']);

    $this->putJson(ZONES_URL."/{$zone->id}", ['name' => 'Matosinhos', 'description' => 'Praia'])
        ->assertOk()
        ->assertJsonPath('data.description', 'Praia');
});

it('deactivates a zone and filters by state', function () {
    $active = Zone::create(['name' => 'Ativa']);
    $inactive = Zone::create(['name' => 'Inativa']);
    authenticateApiUser(userWithRole('admin'));

    $this->patchJson(ZONES_URL."/{$inactive->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->getJson(ZONES_URL.'?active=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);

    $this->patchJson(ZONES_URL."/{$inactive->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('forbids a service provider from managing zones', function () {
    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson(ZONES_URL)->assertForbidden();
    $this->postJson(ZONES_URL, ['name' => 'Gaia'])->assertForbidden();

    $this->assertDatabaseCount('zones', 0);
});

it('saves the zone and location of a property', function () {
    $zone = Zone::create(['name' => 'Porto Centro']);
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/properties', zonePropertyPayload([
        'zone_id' => $zone->id,
        'latitude' => 41.140600,
        'longitude' => -8.611000,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.zone_id', $zone->id)
        ->assertJsonPath('data.latitude', '41.140600')
        ->assertJsonPath('data.longitude', '-8.611000');
});

it('rejects an inactive zone and incomplete coordinates', function () {
    $zone = Zone::create(['name' => 'Fechada', 'is_active' => false]);
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/properties', zonePropertyPayload([
        'zone_id' => $zone->id,
        'latitude' => 41.14,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'zone_id' => 'A zona não existe ou está inativa.',
            'longitude' => 'Indique a longitude junto com a latitude.',
        ]);

    $this->postJson('/api/properties', zonePropertyPayload([
        'latitude' => 120,
        'longitude' => -8.61,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude' => 'A latitude deve estar entre -90 e 90.']);

    $this->assertDatabaseCount('properties', 0);
});

it('replaces the zones of a service provider', function () {
    $porto = Zone::create(['name' => 'Porto']);
    $gaia = Zone::create(['name' => 'Gaia']);
    $closed = Zone::create(['name' => 'Fechada', 'is_active' => false]);
    $provider = createServiceProvider('zonas@example.test');
    authenticateApiUser(userWithRole('supervisor'));
    $url = "/api/service-providers/{$provider->id}/zones";

    $this->putJson($url, ['zone_ids' => [$porto->id, $gaia->id]])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Gaia');

    $this->putJson($url, ['zone_ids' => [$closed->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['zone_ids.0' => 'A zona não existe ou está inativa.']);

    $this->putJson($url, ['zone_ids' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($provider->zones()->count())->toBe(0);
});

it('shows the zones only to staff and to the provider themself', function () {
    $zone = Zone::create(['name' => 'Porto']);
    $provider = createServiceProvider('dono@example.test');
    $provider->zones()->attach($zone);
    $other = createServiceProvider('outro@example.test');
    $url = "/api/service-providers/{$provider->id}/zones";

    authenticateApiUser($provider->user);
    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Porto');
    $this->putJson($url, ['zone_ids' => []])->assertForbidden();

    authenticateApiUser($other->user);
    $this->getJson($url)->assertForbidden();
});
