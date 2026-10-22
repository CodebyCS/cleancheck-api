<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const SERVICE_PROVIDERS_URL = '/api/service-providers';

function validServiceProvider(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ana Teste',
        'email' => 'ana@example.test',
        'password' => 'Password-123',
        'phone' => '912345678',
    ], $overrides);
}

it('creates a service provider linked to a prestador account', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(SERVICE_PROVIDERS_URL, validServiceProvider())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ana Teste')
        ->assertJsonPath('data.email', 'ana@example.test')
        ->assertJsonPath('data.phone', '912345678')
        ->assertJsonPath('data.is_active', true);

    $user = User::where('email', 'ana@example.test')->firstOrFail();

    expect($user->role->name)->toBe('prestador')
        ->and($user->is_active)->toBeTrue()
        ->and($user->serviceProvider)->not->toBeNull()
        ->and($user->serviceProvider->phone)->toBe('912345678');

    $this->getJson(SERVICE_PROVIDERS_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'ana@example.test');
});

it('creates an account that can log in on mobile', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson(SERVICE_PROVIDERS_URL, validServiceProvider())
        ->assertCreated();

    $this->postJson('/api/mobile/login', [
        'email' => 'ana@example.test',
        'password' => 'Password-123',
    ])
        ->assertOk()
        ->assertJsonPath('user.role', 'prestador');
});

it('rejects an email that already has an account', function () {
    userWithRole('prestador')->update(['email' => 'ana@example.test']);

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson(SERVICE_PROVIDERS_URL, validServiceProvider())
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'email' => 'Já existe uma conta com este e-mail.',
        ]);

    $this->assertDatabaseCount('service_providers', 0);
});

it('rejects invalid fields', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->postJson(SERVICE_PROVIDERS_URL, validServiceProvider([
            'email' => 'nao-e-email',
            'password' => 'curta',
            'phone' => '12',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'phone']);

    $this->assertDatabaseCount('service_providers', 0);
});

it('forbids a prestador from creating or listing service providers', function () {
    $this->actingAs(userWithRole('prestador'), 'sanctum')
        ->postJson(SERVICE_PROVIDERS_URL, validServiceProvider())
        ->assertForbidden();

    $this->getJson(SERVICE_PROVIDERS_URL)->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'ana@example.test']);
});

it('requires authentication', function () {
    $this->getJson(SERVICE_PROVIDERS_URL)->assertUnauthorized();
    $this->postJson(SERVICE_PROVIDERS_URL, validServiceProvider())->assertUnauthorized();
});
