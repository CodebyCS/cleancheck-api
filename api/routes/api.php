<?php

use App\Http\Controllers\AuthController;
use App\Http\Resources\UserResource;
use App\Modules\Properties\Http\Controllers\ProfileController;
use App\Modules\Properties\Http\Controllers\PropertyController;
use App\Modules\Properties\Http\Controllers\ServiceProviderController;
use App\Modules\Properties\Http\Controllers\ServiceProviderPhotoController;
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
Route::post('/mobile/refresh', [AuthController::class, 'refreshMobile'])
    ->middleware('throttle:10,1');

// Photo links are generated only for authorized viewers and expire.
Route::get('/service-providers/{serviceProvider}/photo', [ServiceProviderPhotoController::class, 'show'])
    ->whereNumber('serviceProvider')
    ->middleware('signed')
    ->name('service-providers.photo');

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

    Route::get('/profile', [ProfileController::class, 'show']);

    Route::post('/reservations/ical/read', IcalReadController::class);
    Route::post('/reservations/ical/import', IcalImportController::class);

    // Route Tasks
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');

    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task');
    Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])->whereNumber('task');

    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update'])->whereNumber('property');
    Route::patch('/properties/{property}/activate', [PropertyController::class, 'activate'])->whereNumber('property');
    Route::patch('/properties/{property}/deactivate', [PropertyController::class, 'deactivate'])->whereNumber('property');

    Route::get('/service-providers', [ServiceProviderController::class, 'index']);
    Route::post('/service-providers', [ServiceProviderController::class, 'store']);
    Route::put('/service-providers/{serviceProvider}', [ServiceProviderController::class, 'update'])->whereNumber('serviceProvider');
    Route::patch('/service-providers/{serviceProvider}/activate', [ServiceProviderController::class, 'activate'])->whereNumber('serviceProvider');
    Route::patch('/service-providers/{serviceProvider}/deactivate', [ServiceProviderController::class, 'deactivate'])->whereNumber('serviceProvider');
    Route::post('/service-providers/{serviceProvider}/photo', [ServiceProviderPhotoController::class, 'store'])->whereNumber('serviceProvider');
});

