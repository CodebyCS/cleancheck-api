<?php

declare(strict_types=1);

use App\Modules\Billing\Models\Rate;
use App\Modules\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function billingRateProperty(string $name = 'Casa Tarifas'): Property
{
    return Property::create([
        'name' => $name,
        'address' => 'Rua Fictícia 50',
        'postal_code' => '4050-123',
        'city' => 'Porto',
    ]);
}

it('creates a general rate with the default currency', function () {
    $admin = authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/rates', [
        'task_type' => 'checkout_cleaning',
        'amount' => 45.5,
        'valid_from' => '2026-12-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.property_id', null)
        ->assertJsonPath('data.amount', '45.50')
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.valid_from', '2026-12-01')
        ->assertJsonPath('data.created_by_user_id', $admin->id);
});

it('creates a property rate and normalises the currency', function () {
    $property = billingRateProperty();
    authenticateApiUser(userWithRole('supervisor'));

    $this->postJson('/api/rates', [
        'property_id' => $property->id,
        'task_type' => 'checkin_inspection',
        'amount' => '120',
        'currency' => ' brl ',
        'valid_from' => '2026-12-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.property_id', $property->id)
        ->assertJsonPath('data.currency', 'BRL');
});

it('rejects invalid rates', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/rates', [
        'property_id' => 999999,
        'task_type' => 'gardening',
        'amount' => -1,
        'currency' => 'EURO',
        'valid_from' => '01/12/2026',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'property_id' => 'O imóvel não existe.',
            'task_type' => 'O tipo de tarefa é inválido.',
            'amount' => 'O valor não pode ser negativo.',
            'currency' => 'A moeda deve ter 3 letras (ex.: EUR).',
            'valid_from' => 'A data deve estar no formato AAAA-MM-DD.',
        ]);

    $this->postJson('/api/rates', [
        'task_type' => 'checkout_cleaning',
        'amount' => 10.123,
        'valid_from' => '2026-12-01',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'O valor pode ter no máximo 2 casas decimais.']);

    $this->assertDatabaseCount('rates', 0);
});

it('keeps the full history and filters it', function () {
    $property = billingRateProperty();
    $old = Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 40, 'valid_from' => '2026-01-01']);
    $new = Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 45, 'valid_from' => '2026-06-01']);
    $own = Rate::create(['property_id' => $property->id, 'task_type' => 'checkout_cleaning', 'amount' => 60, 'valid_from' => '2026-03-01']);
    Rate::create(['task_type' => 'checkin_inspection', 'amount' => 20, 'valid_from' => '2026-01-01']);
    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/rates?task_type=checkout_cleaning')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $own->id)
        ->assertJsonPath('data.2.id', $old->id);

    $this->getJson('/api/rates?task_type=checkout_cleaning&property_id=general')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson("/api/rates?property_id={$property->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);
});

it('forbids service providers from managing rates', function () {
    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson('/api/rates')->assertForbidden();
    $this->postJson('/api/rates', [
        'task_type' => 'checkout_cleaning',
        'amount' => 45,
        'valid_from' => '2026-12-01',
    ])->assertForbidden();

    $this->assertDatabaseCount('rates', 0);
});
