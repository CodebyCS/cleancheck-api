<?php

use App\Actions\ManageMobileSession;
use App\Models\MobileAuthSession;
use App\Models\MobileRefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('issues two hour access without refresh when remember is false', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);

    $result = app(ManageMobileSession::class)->start(
        $user,
        'test-phone',
        false,
    );

    $token = PersonalAccessToken::findToken($result['token']);
    $session = MobileAuthSession::findOrFail(
        $token->mobile_auth_session_id,
    );

    expect($token->tokenable_id)->toBe($user->id)
        ->and($token->expires_at->timestamp)
        ->toBe(now()->addHours(2)->timestamp)
        ->and($session->expires_at->timestamp)
        ->toBe(now()->addHours(2)->timestamp)
        ->and($result['refresh_token'])->toBeNull()
        ->and($result['refresh_expires_at'])->toBeNull();

    $this->assertDatabaseCount('mobile_refresh_tokens', 0);
});

it('issues two hour access and fourteen day refresh when remembered', function () {
    $this->freezeTime();

    $user = User::factory()->create(['is_active' => true]);

    $result = app(ManageMobileSession::class)->start(
        $user,
        'test-phone',
        true,
    );

    $token = PersonalAccessToken::findToken($result['token']);
    $session = MobileAuthSession::findOrFail(
        $token->mobile_auth_session_id,
    );
    $refresh = MobileRefreshToken::where(
        'mobile_auth_session_id',
        $session->id,
    )->sole();

    expect($token->expires_at->timestamp)
        ->toBe(now()->addHours(2)->timestamp)
        ->and($session->expires_at->timestamp)
        ->toBe(now()->addDays(14)->timestamp)
        ->and($refresh->expires_at->timestamp)
        ->toBe($session->expires_at->timestamp)
        ->and($result['expires_at'])
        ->toBe($token->expires_at->toIso8601String())
        ->and($result['refresh_expires_at'])
        ->toBe($session->expires_at->toIso8601String())
        ->and($refresh->token_hash)
        ->toBe(hash('sha256', $result['refresh_token']))
        ->and($refresh->token_hash)
        ->not->toBe($result['refresh_token'])
        ->and($refresh->used_at)->toBeNull()
        ->and($refresh->revoked_at)->toBeNull()
        ->and($refresh->toArray())->not->toHaveKey('token_hash');
});

it('rejects an inactive account without issuing credentials', function () {
    $user = User::factory()->create(['is_active' => false]);

    try {
        app(ManageMobileSession::class)->start(
            $user,
            'test-phone',
            true,
        );

        $this->fail('An inactive account must be rejected.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        expect($exception->getStatusCode())->toBe(401);
    }

    $this->assertDatabaseCount('mobile_auth_sessions', 0);
    $this->assertDatabaseCount('mobile_refresh_tokens', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});
