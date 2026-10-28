<?php

namespace App\Actions;

use App\Models\MobileAuthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
}
