<?php

declare(strict_types=1);

use App\Modules\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function timezonePropertyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Casa do Fuso',
        'address' => 'Rua Fictícia 5',
        'postal_code' => '1100-001',
        'city' => 'Lisboa',
    ], $overrides);
}

it('uses Europe/Lisbon when no timezone is sent', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson('/api/properties', timezonePropertyPayload())
        ->assertCreated()
        ->assertJsonPath('data.timezone', 'Europe/Lisbon');

    expect(Property::first()->timezone)->toBe('Europe/Lisbon');
});

it('stores a valid timezone on create', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson('/api/properties', timezonePropertyPayload(['timezone' => 'Atlantic/Azores']))
        ->assertCreated()
        ->assertJsonPath('data.timezone', 'Atlantic/Azores');
});

it('rejects an invalid timezone', function (mixed $timezone) {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson('/api/properties', timezonePropertyPayload(['timezone' => $timezone]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');

    expect(Property::count())->toBe(0);
})->with([
    'unknown name' => 'Lisboa/Portugal',
    'offset instead of identifier' => '+01:00',
    'empty' => '',
    'not a string' => 123,
]);

it('updates the timezone of a property', function () {
    $property = Property::create(timezonePropertyPayload());

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/properties/{$property->id}", timezonePropertyPayload(['timezone' => 'Europe/Madrid']))
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Europe/Madrid');

    expect($property->fresh()->timezone)->toBe('Europe/Madrid');
});

it('keeps the current timezone when the update does not send one', function () {
    $property = Property::create(timezonePropertyPayload(['timezone' => 'Atlantic/Madeira']));

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/properties/{$property->id}", timezonePropertyPayload(['name' => 'Casa do Fuso T1']))
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Atlantic/Madeira');
});
