<?php

declare(strict_types=1);

use App\Modules\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function existingProperty(array $overrides = []): Property
{
    return Property::create(array_merge([
        'name' => 'Casa da Ribeira',
        'address' => 'Rua Fictícia 10',
        'postal_code' => '4050-123',
        'city' => 'Porto',
    ], $overrides));
}

function propertyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Casa da Ribeira',
        'address' => 'Rua Fictícia 10',
        'postal_code' => '4050-123',
        'city' => 'Porto',
    ], $overrides);
}

it('updates a property with contract fields and persists them', function () {
    $property = existingProperty();
    $responsible = createServiceProvider('ana@example.test');

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/properties/{$property->id}", propertyPayload([
            'name' => 'Casa da Ribeira T2',
            'responsible_service_provider_id' => $responsible->id,
            'base_cleaning_amount' => 45.5,
            'default_check_in_time' => '15:00',
            'default_check_out_time' => '11:00',
        ]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Casa da Ribeira T2')
        ->assertJsonPath('data.responsible_service_provider_id', $responsible->id)
        ->assertJsonPath('data.base_cleaning_amount', '45.50')
        ->assertJsonPath('data.default_check_in_time', '15:00')
        ->assertJsonPath('data.default_check_out_time', '11:00');

    expect($property->fresh())
        ->name->toBe('Casa da Ribeira T2')
        ->responsible_service_provider_id->toBe($responsible->id);
});

it('rejects invalid contract fields', function () {
    $property = existingProperty();

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/properties/{$property->id}", propertyPayload([
            'base_cleaning_amount' => -1,
            'default_check_in_time' => '25:00',
            'default_check_out_time' => 'manha',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'base_cleaning_amount' => 'O valor base não pode ser negativo.',
            'default_check_in_time',
            'default_check_out_time',
        ]);
});

it('rejects an inactive responsible provider', function () {
    $property = existingProperty();
    $inactive = createServiceProvider('inativo@example.test');
    $inactive->user->update(['is_active' => false]);

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/properties/{$property->id}", propertyPayload([
            'responsible_service_provider_id' => $inactive->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'responsible_service_provider_id' => 'O prestador responsável não existe ou está inativo.',
        ]);
});

it('deactivates and activates a property without deleting it', function () {
    $property = existingProperty();
    $this->actingAs(userWithRole('supervisor'), 'sanctum');

    $this->patchJson("/api/properties/{$property->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('properties', ['id' => $property->id, 'is_active' => false]);

    $this->patchJson("/api/properties/{$property->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('filters properties by active state', function () {
    existingProperty(['name' => 'Ativo']);
    existingProperty(['name' => 'Inativo', 'is_active' => false]);
    $this->actingAs(userWithRole('admin'), 'sanctum');

    $this->getJson('/api/properties?active=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Ativo');

    $this->getJson('/api/properties?active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Inativo');

    $this->getJson('/api/properties')->assertJsonCount(2, 'data');
});

it('forbids a prestador from updating or deactivating properties', function () {
    $property = existingProperty();
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    $this->putJson("/api/properties/{$property->id}", propertyPayload(['name' => 'X']))
        ->assertForbidden();
    $this->patchJson("/api/properties/{$property->id}/deactivate")->assertForbidden();

    expect($property->fresh())
        ->name->toBe('Casa da Ribeira')
        ->is_active->toBeTrue();
});

it('returns 404 for a property that does not exist', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson('/api/properties/999', propertyPayload())
        ->assertNotFound();
});
