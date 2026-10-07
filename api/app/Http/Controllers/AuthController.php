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



class AuthController extends Controller
{
    public function loginWeb(LoginRequest $request) : UserResource
    {
        $credentials = $request->only('email', 'password');
        $credentials['is_active'] = true;

        if (!Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Email ou password invalidos.'],
            ]);
        }

        $request->session()->regenerate();

        return new UserResource($request->user('web'));
    }

    public function logoutWeb(Request $request) : Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function loginMobile(LoginRequest $request) : JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if(
            ! $user
            || ! Hash::check($request->input('password'), $user->password)
            || ! $user->is_active
        ) {
            throw ValidationException::withMessages([
                'email' => ['Email oou password invalidos'],
            ]);
        }

        $expiresAt = now()->addHours(2);
        $token = $user->createToken(
            $request->input('device_name', 'mobile'),
            ['*'],
            $expiresAt,
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => (new UserResource($user))->resolve(),
        ]);
    }

    public function logoutMobile(Request $request) : Response
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);
        $token->delete();

        return response()->noContent();
    }
}
