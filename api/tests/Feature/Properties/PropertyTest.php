<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const PROPERTIES_URL = '/api/properties';

function validProperty(array $overrides = []): array
{
    return array_merge([
        'name' => 'Casa da Ribeira',
        'address' => 'Rua Fictícia 10',
        'postal_code' => '4050-123',
        'city' => 'Porto',
        'access_instructions' => 'Chave no cofre junto à porta.',
    ], $overrides);
}

it('creates a property and returns it when listing', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson(PROPERTIES_URL, validProperty())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Casa da Ribeira')
        ->assertJsonPath('data.postal_code', '4050-123')
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('properties', [
        'name' => 'Casa da Ribeira',
        'city' => 'Porto',
    ]);

    $this->getJson(PROPERTIES_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Casa da Ribeira');
});

it('allows a supervisor to create a property', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(PROPERTIES_URL, validProperty())
        ->assertCreated();
});

it('rejects missing required fields', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson(PROPERTIES_URL, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'address', 'postal_code', 'city']);

    $this->assertDatabaseCount('properties', 0);
});

it('rejects an invalid postal code', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson(PROPERTIES_URL, validProperty(['postal_code' => '4050']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'postal_code' => 'O código postal deve ter o formato 1234-567.',
        ]);
});

it('forbids a prestador from creating or listing properties', function () {
    $this->actingAs(userWithRole('prestador'), 'sanctum')
        ->postJson(PROPERTIES_URL, validProperty())
        ->assertForbidden();

    $this->getJson(PROPERTIES_URL)->assertForbidden();

    $this->assertDatabaseCount('properties', 0);
});

it('requires authentication', function () {
    $this->getJson(PROPERTIES_URL)->assertUnauthorized();
    $this->postJson(PROPERTIES_URL, validProperty())->assertUnauthorized();
});
