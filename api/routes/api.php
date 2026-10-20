<?php

use App\Http\Controllers\AuthController;
use App\Http\Resources\UserResource;
use App\Modules\Reservations\Http\Controllers\IcalImportController;
use App\Modules\Reservations\Http\Controllers\IcalReadController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'application' => 'CleanCheck', 'status' => 'ok',
    ]);
});

Route::post('/mobile/login', [AuthController::class, 'loginMobile'])
    ->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/user', function (Request $request): UserResource {
        return new UserResource($request->user());
    });
    Route::post('/mobile/logout', [AuthController::class, 'logoutMobile']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/reservations/ical/read', IcalReadController::class);
    Route::post('/reservations/ical/import', IcalImportController::class);
});
