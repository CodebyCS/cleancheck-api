<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('logs in with remembered credentials and refreshes without bearer authentication', function () {
    $this->freezeTime();

    $user = User::factory()->create([
        'password' => 'Secret-123!',
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'Secret-123!',
        'remember' => true,
    ])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure([
            'token',
            'expires_at',
            'refresh_token',
            'refresh_expires_at',
            'user',
        ]);

    $initial = $login->json();

    $this->travel(3)->hours();

    $renewal = $this->postJson('/api/mobile/refresh', [
        'refresh_token' => $initial['refresh_token'],
    ])->assertOk();

    expect($renewal->json('token'))->not->toBe($initial['token'])
        ->and($renewal->json('refresh_token'))
        ->not->toBe($initial['refresh_token'])
        ->and($renewal->json('refresh_expires_at'))
        ->toBe($initial['refresh_expires_at']);

    // Reinicia os guards para o próximo pedido validar o novo Bearer.
    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->withToken($renewal->json('token'))
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('does not issue refresh credentials without remember', function () {
    $user = User::factory()->create([
        'password' => 'Secret-123!',
        'is_active' => true,
    ]);

    $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'Secret-123!',
        'remember' => false,
    ])
        ->assertOk()
        ->assertJsonPath('refresh_token', null)
        ->assertJsonPath('refresh_expires_at', null);
});

it('rejects missing or malformed refresh credentials', function () {
    foreach ([
        [],
        ['refresh_token' => 'invalid'],
        ['refresh_token' => str_repeat('z', 64)],
    ] as $payload) {
        $this->postJson('/api/mobile/refresh', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('refresh_token');
    }
});

it('rejects an unknown refresh credential', function () {
    $this->postJson('/api/mobile/refresh', [
        'refresh_token' => bin2hex(random_bytes(32)),
    ])->assertUnauthorized();
});

it('rejects reused refresh credentials and invalidates the replacement access token', function () {
    $user = User::factory()->create([
        'password' => 'Secret-123!',
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'Secret-123!',
        'remember' => true,
    ])->assertOk();

    $renewal = $this->postJson('/api/mobile/refresh', [
        'refresh_token' => $login->json('refresh_token'),
    ])->assertOk();

    $this->postJson('/api/mobile/refresh', [
        'refresh_token' => $login->json('refresh_token'),
    ])->assertUnauthorized();

    expect(
        PersonalAccessToken::findToken($renewal->json('token')),
    )->toBeNull();

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->withToken($renewal->json('token'))
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('logs out one device and revokes its access and refresh credentials', function () {
    $user = User::factory()->create([
        'password' => 'Secret-123!',
        'is_active' => true,
    ]);

    $credentials = [
        'email' => $user->email,
        'password' => 'Secret-123!',
        'remember' => true,
    ];

    $phone = $this->postJson('/api/mobile/login', [
        ...$credentials,
        'device_name' => 'phone',
    ])->assertOk()->json();

    $tablet = $this->postJson('/api/mobile/login', [
        ...$credentials,
        'device_name' => 'tablet',
    ])->assertOk()->json();

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->withToken($phone['token'])
        ->postJson('/api/mobile/logout')
        ->assertNoContent();

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->withToken($phone['token'])
        ->getJson('/api/user')
        ->assertUnauthorized();

    $this->withoutHeader('Authorization');
    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->postJson('/api/mobile/refresh', [
        'refresh_token' => $phone['refresh_token'],
    ])->assertUnauthorized();

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->withToken($tablet['token'])
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    $this->withoutHeader('Authorization');
    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->postJson('/api/mobile/refresh', [
        'refresh_token' => $tablet['refresh_token'],
    ])->assertOk();
});

it('logs out with refresh after access expires', function () {
    $this->freezeTime();

    $user = User::factory()->create([
        'password' => 'Secret-123!',
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'Secret-123!',
        'remember' => true,
    ])->assertOk()->json();

    $this->travel(3)->hours();

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->withToken($login['token'])
        ->getJson('/api/user')
        ->assertUnauthorized();

    $this->withoutHeader('Authorization');
    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->postJson('/api/mobile/logout-with-refresh', [
        'refresh_token' => $login['refresh_token'],
    ])->assertNoContent();

    expect(
        PersonalAccessToken::findToken($login['token']),
    )->toBeNull();

    $this->postJson('/api/mobile/refresh', [
        'refresh_token' => $login['refresh_token'],
    ])->assertUnauthorized();
});

it('rejects logout with an unknown refresh credential', function () {
    $this->postJson('/api/mobile/logout-with-refresh', [
        'refresh_token' => bin2hex(random_bytes(32)),
    ])->assertUnauthorized();
});
