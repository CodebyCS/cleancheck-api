<?php

namespace App\Actions;

use App\Models\MobileAuthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\MobileRefreshToken;

class ManageMobileSession
{
    /**
     * @return array{
     *     token: string,
     *     expires_at: string,
     *     refresh_token: string|null,
     *     refresh_expires_at: string|null
     * }
     */
    public function start(
        User $user,
        string $deviceName,
        bool $remember,
    ): array {
        abort_unless($user->is_active, 401);

        return DB::transaction(function () use (
            $user,
            $deviceName,
            $remember,
        ): array {
            $deadline = $remember
                ? now()->addDays(14)
                : now()->addHours(2);

            $session = MobileAuthSession::create([
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'expires_at' => $deadline,
            ]);

            $accessExpiresAt = now()->addHours(2);

            if ($accessExpiresAt->greaterThan($deadline)) {
                $accessExpiresAt = $deadline->copy();
            }

            $accessToken = $user->createToken(
                $deviceName,
                ['*'],
                $accessExpiresAt,
            );

            $accessToken->accessToken->forceFill([
                'mobile_auth_session_id' => $session->id,
            ])->save();

            $refreshToken = null;

            if ($remember) {
                $refreshToken = bin2hex(random_bytes(32));

                $session->refreshTokens()->create([
                    'token_hash' => hash('sha256', $refreshToken),
                    'expires_at' => $deadline,
                ]);
            }

            return [
                'token' => $accessToken->plainTextToken,
                'expires_at' => $accessExpiresAt->toIso8601String(),
                'refresh_token' => $refreshToken,
                'refresh_expires_at' => $remember
                    ? $deadline->toIso8601String()
                    : null,
            ];
        });
    }

    /**
     * @return array{
     *     token: string,
     *     expires_at: string,
     *     refresh_token: string,
     *     refresh_expires_at: string
     * }
     */
    public function refresh(string $plainRefreshToken): array
    {
        $hash = hash('sha256', $plainRefreshToken);

        $sessionId = MobileRefreshToken::where('token_hash', $hash)
            ->value('mobile_auth_session_id');

        abort_if($sessionId === null, 401, 'Credencial de renovação inválida.');

        $result = DB::transaction(function () use ($sessionId, $hash): ?array {
            $session = MobileAuthSession::whereKey($sessionId)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return null;
            }

            $refresh = $session->refreshTokens()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($refresh === null) {
                return null;
            }

            $user = $session->user()
                ->lockForUpdate()
                ->first();

            if (
                $session->revoked_at !== null
                || $session->expires_at->lessThanOrEqualTo(now())
                || $refresh->used_at !== null
                || $refresh->revoked_at !== null
                || $refresh->expires_at->lessThanOrEqualTo(now())
                || $user === null
                || ! $user->is_active
            ) {
                $this->revokeSession($session);

                return null;
            }

            $refresh->forceFill([
                'used_at' => now(),
            ])->save();

            // Replace the previous access token during rotation.
            $session->accessTokens()->delete();

            $accessExpiresAt = now()->addHours(2);

            if ($accessExpiresAt->greaterThan($session->expires_at)) {
                $accessExpiresAt = $session->expires_at->copy();
            }

            $accessToken = $user->createToken(
                $session->device_name,
                ['*'],
                $accessExpiresAt,
            );

            $accessToken->accessToken->forceFill([
                'mobile_auth_session_id' => $session->id,
            ])->save();

            $newRefreshToken = bin2hex(random_bytes(32));

            $session->refreshTokens()->create([
                'token_hash' => hash('sha256', $newRefreshToken),
                'expires_at' => $session->expires_at,
            ]);

            return [
                'token' => $accessToken->plainTextToken,
                'expires_at' => $accessExpiresAt->toIso8601String(),
                'refresh_token' => $newRefreshToken,
                'refresh_expires_at' => $session->expires_at->toIso8601String(),
            ];
        });

        abort_if($result === null, 401, 'Sessão terminada. Inicie sessão.');

        return $result;
    }

    public function logout(
        User $user,
        \Laravel\Sanctum\PersonalAccessToken $token,
    ): void {
        abort_unless(
            $token->tokenable_type === $user->getMorphClass()
            && (string) $token->tokenable_id === (string) $user->id,
            403,
        );

        DB::transaction(function () use ($user, $token): void {
            // Support tokens issued before mobile authentication sessions.
            if ($token->mobile_auth_session_id === null) {
                $token->delete();

                return;
            }

            $session = MobileAuthSession::whereKey(
                $token->mobile_auth_session_id,
            )
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                $token->delete();

                return;
            }

            $this->revokeSession($session);
        });
    }

    public function logoutWithRefresh(string $plainRefreshToken): void
    {
        $hash = hash('sha256', $plainRefreshToken);

        $sessionId = MobileRefreshToken::where('token_hash', $hash)
            ->value('mobile_auth_session_id');

        abort_if($sessionId === null, 401, 'Credencial inválida.');

        $revoked = DB::transaction(function () use ($sessionId, $hash): bool {
            $session = MobileAuthSession::whereKey($sessionId)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return false;
            }

            $refresh = $session->refreshTokens()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($refresh === null) {
                return false;
            }

            // Allows you to log out even if the credential has expired
            // or has already been used, without issuing new credentials.
            $this->revokeSession($session);

            return true;
        });

        abort_unless($revoked, 401, 'Credencial inválida.');
    }

    public function revokeForUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $sessions = MobileAuthSession::where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $this->revokeSession($session);
            }

            // Also revoke tokens issued before mobile authentication sessions.
            $user->tokens()->delete();
        });
    }

    private function revokeSession(MobileAuthSession $session): void
    {
        $session->forceFill([
            'revoked_at' => $session->revoked_at ?? now(),
        ])->save();

        $session->refreshTokens()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $session->accessTokens()->delete();
    }
}
