<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use App\Actions\ManageMobileSession;
use App\Http\Requests\Auth\RefreshTokenRequest;

class AuthController extends Controller
{
    public function loginWeb(LoginRequest $request): UserResource
    {
        $credentials = $request->only('email', 'password');
        $credentials['is_active'] = true;

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Email ou password invalidos.'],
            ]);
        }

        $request->session()->regenerate();

        $expiresAt = $request->boolean('remember')
            ? now()->addDays(30)
            : now()->addHours(2);
        $request->session()->put(
            'access_expires_at', $expiresAt->timestamp
        );


        return (new UserResource($request->user('web')))
            ->additional([
                'meta' => [
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);

    }

    public function logoutWeb(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function loginMobile(
        LoginRequest $request,
        ManageMobileSession $sessions,
    ): JsonResponse {
        $user = User::where('email', $request->input('email'))->first();

        if (
            ! $user
            || ! Hash::check($request->input('password'), $user->password)
            || ! $user->is_active
        ) {
            throw ValidationException::withMessages([
                'email' => ['Email ou password invalidos.'],
            ]);
        }

        $credentials = $sessions->start(
            $user,
            $request->input('device_name', 'mobile'),
            $request->boolean('remember'),
        );

        return response()->json([
            ...$credentials,
            'user' => (new UserResource($user))->resolve(),
        ]);
    }

    public function refreshMobile(
        RefreshTokenRequest $request,
        ManageMobileSession $sessions,
    ): JsonResponse {
        return response()->json(
            $sessions->refresh(
                $request->validated('refresh_token'),
            ),
        );
    }

    public function logoutMobile(
        Request $request,
        ManageMobileSession $sessions,
    ): Response {
        $user = $request->user();
        $token = $user->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 403);

        $sessions->logout($user, $token);

        return response()->noContent();
}
}
