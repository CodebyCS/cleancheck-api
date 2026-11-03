<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->redirectGuestsTo(
            fn (Request $request) => null,
        );

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'access.valid' => \App\Http\Middleware\EnsureAccessIsValid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'login', 'logout')
                || $request->expectsJson(),
        );

        $exceptions->render(
            function (AuthenticationException $exception, Request $request) {
                if (
                    $request->is('api/*', 'login', 'logout')
                    || $request->expectsJson()
                ) {
                    return response()->json([
                        'message' => 'Não autorizado',
                    ], 401);
                }

                return null;
            },
        );
    })->create();
