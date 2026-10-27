<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function providerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ana Teste',
        'email' => 'ana@example.test',
        'phone' => '912345678',
        'address' => 'Rua das Flores 1',
        'postal_code' => '4000-001',
        'city' => 'Porto',
    ], $overrides);
}

it('updates the account and provider data in one request', function () {
    $provider = createServiceProvider('ana@example.test');

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/service-providers/{$provider->id}", providerPayload([
            'name' => 'Ana Silva',
            'phone' => '919999999',
        ]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana Silva')
        ->assertJsonPath('data.email', 'ana@example.test')
        ->assertJsonPath('data.phone', '919999999')
        ->assertJsonPath('data.address', 'Rua das Flores 1')
        ->assertJsonPath('data.postal_code', '4000-001');

    $this->assertDatabaseHas('users', ['id' => $provider->user_id, 'name' => 'Ana Silva']);
    $this->assertDatabaseHas('service_providers', ['id' => $provider->id, 'city' => 'Porto']);
});

it('keeps the current email and password when they are not changed', function () {
    $provider = createServiceProvider('ana@example.test');
    $passwordHash = $provider->user->password;

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/service-providers/{$provider->id}", providerPayload())
        ->assertOk();

    expect($provider->user->fresh()->password)->toBe($passwordHash);
});

it('changes the password only when one is sent', function () {
    $provider = createServiceProvider('ana@example.test');

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/service-providers/{$provider->id}", providerPayload([
            'password' => 'Nova-Password-1',
        ]))
        ->assertOk();

    expect(Hash::check('Nova-Password-1', $provider->user->fresh()->password))->toBeTrue();
});

it('rejects an email used by another account', function () {
    $provider = createServiceProvider('ana@example.test');
    createServiceProvider('bruno@example.test');

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->putJson("/api/service-providers/{$provider->id}", providerPayload([
            'email' => 'bruno@example.test',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Já existe uma conta com este e-mail.']);
});

it('deactivates a provider, revokes tokens and blocks login while keeping the record', function () {
    $provider = createServiceProvider('ana@example.test');
    $provider->user->createToken('mobile');

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->patchJson("/api/service-providers/{$provider->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('service_providers', ['id' => $provider->id]);
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->postJson('/api/mobile/login', [
        'email' => 'ana@example.test',
        'password' => 'password',
    ])->assertUnprocessable();
});

it('reactivates a provider so the account can log in again', function () {
    $provider = createServiceProvider('ana@example.test');
    $provider->user->update(['is_active' => false]);

    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->patchJson("/api/service-providers/{$provider->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    $this->postJson('/api/mobile/login', [
        'email' => 'ana@example.test',
        'password' => 'password',
    ])->assertOk();
});

it('filters service providers by active state', function () {
    createServiceProvider('ana@example.test');
    createServiceProvider('bruno@example.test')->user->update(['is_active' => false]);
    $this->actingAs(userWithRole('admin'), 'sanctum');

    $this->getJson('/api/service-providers?active=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'ana@example.test');

    $this->getJson('/api/service-providers?active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'bruno@example.test');
});

it('forbids a prestador from updating or deactivating providers', function () {
    $provider = createServiceProvider('ana@example.test');
    $this->actingAs(userWithRole('prestador'), 'sanctum');

    $this->putJson("/api/service-providers/{$provider->id}", providerPayload(['name' => 'X']))
        ->assertForbidden();
    $this->patchJson("/api/service-providers/{$provider->id}/deactivate")->assertForbidden();

    expect($provider->user->fresh()->is_active)->toBeTrue();
});
