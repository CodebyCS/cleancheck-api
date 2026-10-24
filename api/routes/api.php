<?php

use App\Http\Controllers\AuthController;
use App\Http\Resources\UserResource;
use App\Modules\Properties\Http\Controllers\PropertyController;
use App\Modules\Properties\Http\Controllers\ServiceProviderController;
use App\Modules\Reservations\Http\Controllers\IcalImportController;
use App\Modules\Reservations\Http\Controllers\IcalReadController;
use App\Modules\Tasks\Http\Controllers\TaskController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Diagnosis
Route::get('/health', function (): JsonResponse {
    return response()->json([
        'application' => 'CleanCheck', 'status' => 'ok',
    ]);
});

// The login must be accessible without authentication.
Route::post('/mobile/login', [AuthController::class, 'loginMobile'])
    ->middleware('throttle:5,1');

// Allow an authenticated mobile session to be terminated.
Route::post('/mobile/logout', [AuthController::class, 'logoutMobile'])
    ->middleware('auth:sanctum');

// Transactions that require identification and an active account.
Route::middleware(['auth:sanctum', 'access.valid'])->group(function () : void {
    Route::get('/user', function (Request $request) {
            $token = $request->user()->currentAccessToken();
            $expiresAt = $token instanceof
                \Laravel\Sanctum\PersonalAccessToken
                ? $token->expires_at?->toIso8601String()
                : \Carbon\CarbonImmutable::createFromTimestamp(
                    (int) $request->session()->get('access_expires_at')
                )->toIso8601String();

            return (new UserResource($request->user()))
                ->additional(['meta' => ['expires_at' => $expiresAt]]);

    });

    Route::post('/reservations/ical/read', IcalReadController::class);

    // Route Tasks
    Route::get('/tasks', [TaskController::class, 'index']);

    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task');
    Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])->whereNumber('task');

    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);

    Route::get('/service-providers', [ServiceProviderController::class, 'index']);
    Route::post('/service-providers', [ServiceProviderController::class, 'store']);
});


//TODO: Rotas repetida / fora da operação de verificação
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/reservations/ical/read', IcalReadController::class);
    Route::post('/reservations/ical/import', IcalImportController::class);
});
