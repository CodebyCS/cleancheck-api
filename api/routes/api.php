<?php

use App\Http\Controllers\AuthController;
use App\Http\Resources\UserResource;
use App\Modules\Reservations\Http\Controllers\IcalImportController;
use App\Modules\Reservations\Http\Controllers\IcalReadController;
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
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', function (Request $request) {
        return new UserResource($request->user());
    });

    Route::post('/reservations/ical/read', IcalReadController::class);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/reservations/ical/read', IcalReadController::class);
    Route::post('/reservations/ical/import', IcalImportController::class);
});
