<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('/api/testing/management-access', function () {
        return response()->json(['allowed' => true]);
    })->middleware([
        'api',
        'auth:sanctum',
        'access.valid',
        'role:admin,supervisor',
    ]);

    Route::get('/api/testing/admin-access', function () {
        return response()->json(['allowed' => true]);
    })->middleware([
        'api',
        'auth:sanctum',
        'access.valid',
        'role:admin',
    ]);
});

it('allows the configured management roles', function (string $role) {
    authenticateApiUser(userWithRole($role));

    $this->getJson('/api/testing/management-access')
        ->assertOk()
        ->assertJsonPath('allowed', true);
})->with(['admin', 'supervisor']);

it('rejects a role outside the configured list', function () {
    authenticateApiUser(userWithRole('service_provider'));

    $this->getJson('/api/testing/management-access')
        ->assertForbidden();
});

it('restricts an admin-only route', function (string $role) {
    authenticateApiUser(userWithRole($role));

    $this->getJson('/api/testing/admin-access')
        ->assertForbidden();
})->with(['supervisor', 'service_provider']);

it('allows an administrator on an admin-only route', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/testing/admin-access')
        ->assertOk();
});

it('rejects unauthenticated access', function () {
    $this->getJson('/api/testing/management-access')
        ->assertUnauthorized();
});

it('rejects an inactive account before role authorization', function () {
    $user = userWithRole('admin');

    authenticateApiUser($user);

    $user->is_active = false;
    $user->save();

    $this->getJson('/api/testing/management-access')
        ->assertUnauthorized();
});
