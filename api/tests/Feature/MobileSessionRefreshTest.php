<?php

use App\Actions\ManageMobileSession;
use App\Models\MobileAuthSession;
use App\Models\MobileRefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('rotates credentials after access expires without extending the session', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);
    $action = app(ManageMobileSession::class);
    $initial = $action->start($user, 'phone', true);

    $this->travel(3)->hours();

    $renewed = $action->refresh($initial['refresh_token']);

    expect($renewed['token'])->not->toBe($initial['token'])
        ->and($renewed['refresh_token'])
        ->not->toBe($initial['refresh_token'])
        ->and($renewed['refresh_expires_at'])
        ->toBe($initial['refresh_expires_at'])
        ->and($renewed['expires_at'])
        ->toBe(now()->addHours(2)->toIso8601String())
        ->and(PersonalAccessToken::findToken($initial['token']))
        ->toBeNull()
        ->and(PersonalAccessToken::findToken($renewed['token']))
        ->not->toBeNull();

    $previous = MobileRefreshToken::where(
        'token_hash',
        hash('sha256', $initial['refresh_token']),
    )->sole();

    expect($previous->used_at)->not->toBeNull();
});

it('limits renewed access to the remaining session time', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);
    $action = app(ManageMobileSession::class);
    $initial = $action->start($user, 'phone', true);

    $this->travelTo(now()->addDays(14)->subMinutes(30));

    $renewed = $action->refresh($initial['refresh_token']);

    expect($renewed['expires_at'])
        ->toBe($initial['refresh_expires_at'])
        ->and($renewed['refresh_expires_at'])
        ->toBe($initial['refresh_expires_at']);
});

it('revokes only the affected device when an old refresh token is reused', function () {
    $user = User::factory()->create(['is_active' => true]);
    $action = app(ManageMobileSession::class);

    $phone = $action->start($user, 'phone', true);
    $tablet = $action->start($user, 'tablet', true);
    $renewed = $action->refresh($phone['refresh_token']);

    try {
        $action->refresh($phone['refresh_token']);
        $this->fail('A used refresh token must be rejected.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(401);
    }

    expect(PersonalAccessToken::findToken($renewed['token']))
        ->toBeNull()
        ->and(PersonalAccessToken::findToken($tablet['token']))
        ->not->toBeNull();

    $replacement = MobileRefreshToken::where(
        'token_hash',
        hash('sha256', $renewed['refresh_token']),
    )->sole();

    expect($replacement->revoked_at)->not->toBeNull();
});

it('rejects refresh at the absolute fourteen day deadline', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);
    $action = app(ManageMobileSession::class);
    $initial = $action->start($user, 'phone', true);

    $this->travel(14)->days();

    try {
        $action->refresh($initial['refresh_token']);
        $this->fail('An expired session must be rejected.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(401);
    }

    expect(MobileAuthSession::sole()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::findToken($initial['token']))
        ->toBeNull();
});

it('rejects refresh after the account is deactivated', function () {
    $user = User::factory()->create(['is_active' => true]);
    $action = app(ManageMobileSession::class);
    $initial = $action->start($user, 'phone', true);

    $user->is_active = false;
    $user->save();

    expect($user->fresh()->is_active)->toBeFalse();

    try {
        $action->refresh($initial['refresh_token']);
        $this->fail('An inactive account must not renew access.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(401);
    }

    expect(MobileAuthSession::sole()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::findToken($initial['token']))
        ->toBeNull();
});

it('rejects an unknown refresh token without revoking another session', function () {
    $user = User::factory()->create(['is_active' => true]);
    $action = app(ManageMobileSession::class);
    $initial = $action->start($user, 'phone', true);

    try {
        $action->refresh(bin2hex(random_bytes(32)));
        $this->fail('An unknown refresh token must be rejected.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(401);
    }

    expect(MobileAuthSession::sole()->revoked_at)->toBeNull()
        ->and(PersonalAccessToken::findToken($initial['token']))
        ->not->toBeNull();
});
