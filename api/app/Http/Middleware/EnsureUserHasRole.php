<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles,
    ): Response {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->is_active && $user->hasRole(...$roles),
            403,
            'Não tens permissão para realizar esta operação.',
        );

        return $next($request);
    }
}