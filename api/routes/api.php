<?php

use App\Http\Controllers\AuthController;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use App\Modules\Reservations\Http\Controllers\IcalReadController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/reservations/ical/read', IcalReadController::class);
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
    
    // Route Tasks
    Route::get('/tasks', [TaskController::class, 'index']);
});
});