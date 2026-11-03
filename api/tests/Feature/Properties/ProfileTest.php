<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns only the profile of the authenticated provider', function () {
    Storage::fake('local');
    $ana = createServiceProvider('ana@example.test', ['city' => 'Porto']);
    $bruno = createServiceProvider('bruno@example.test', ['city' => 'Lisboa']);
    $ana->forceFill(['photo_path' => 'service-providers/ana.jpg'])->save();

    $this->actingAs($ana->user, 'sanctum')
        ->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $ana->id)
        ->assertJsonPath('data.email', 'ana@example.test')
        ->assertJsonPath('data.city', 'Porto')
        ->assertJsonPath('data.photo_url', fn ($url) => is_string($url));

    $this->actingAs($bruno->user, 'sanctum')
        ->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $bruno->id)
        ->assertJsonPath('data.email', 'bruno@example.test')
        ->assertJsonPath('data.photo_url', null);
});

it('does not let a provider read other providers', function () {
    $ana = createServiceProvider('ana@example.test');
    $bruno = createServiceProvider('bruno@example.test');

    $this->actingAs($ana->user, 'sanctum')
        ->getJson('/api/service-providers')
        ->assertForbidden();

    $this->putJson("/api/service-providers/{$bruno->id}", [])->assertForbidden();
});

it('returns 404 for an account without a provider record', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->getJson('/api/profile')
        ->assertNotFound();
});

it('requires an active authenticated account', function () {
    $this->getJson('/api/profile')->assertUnauthorized();

    $ana = createServiceProvider('ana@example.test');
    $ana->user->update(['is_active' => false]);

    $this->actingAs($ana->user->fresh(), 'sanctum')
        ->getJson('/api/profile')
        ->assertUnauthorized();
});
