<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccessIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        $token = $user->currentAccessToken();
        $isMobile = $token instanceof PersonalAccessToken;
        $deadline = $request->hasSession()
            ? $request->session()->get('access_expires_at')
            : null;
        $expired = ! $isMobile && (
            ! is_numeric($deadline)
            || now()->timestamp >= (int) $deadline
        );

        if (! $user->is_active || $expired) {
            if ($isMobile) {
                $token->delete();
            } elseif ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Acesso terminado. Inicie sessao.',
            ], 401);
        }

        return $next($request);
    }
}
