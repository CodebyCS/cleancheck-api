<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('sets the web session deadline according to remember', function (
    bool $remember,
    int $hours,
) {
    $this->freezeTime();

    $user = User::factory()->create([
        'password' => 'Secret-123!',
        'is_active' => true,
    ]);

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'Secret-123!',
        'remember' => $remember,
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath(
            'meta.expires_at',
            now()->addHours($hours)->toIso8601String(),
        )
        ->assertSessionHas(
            'access_expires_at',
            now()->addHours($hours)->timestamp,
        );

    $this->assertAuthenticatedAs($user, 'web');
})->with([
    'short access' => [false, 2],
    'remembered access' => [true, 14 * 24],
]);

it('accepts web access before the deadline without extending it', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);
    $deadline = now()->addDays(14)->timestamp;

    $this->actingAs($user, 'web')
        ->withSession(['access_expires_at' => $deadline])
        ->withHeader('Origin', 'http://localhost:5173')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertSessionHas('access_expires_at', $deadline);
});

it('ends web access at its absolute deadline', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user, 'web')
        ->withSession([
            'access_expires_at' => now()->timestamp,
        ])
        ->withHeader('Origin', 'http://localhost:5173')
        ->getJson('/api/user')
        ->assertUnauthorized();

    $this->assertGuest('web');
});

it('blocks an inactive account with an existing web session', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user, 'web');

    $user->is_active = false;
    $user->save();

    $this->withSession([
        'access_expires_at' => now()->addHour()->timestamp,
    ])
        ->withHeader('Origin', 'http://localhost:5173')
        ->getJson('/api/user')
        ->assertUnauthorized();

    $this->assertGuest('web');
});
