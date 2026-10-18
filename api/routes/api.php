<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Modules\Reservations\Http\Controllers\IcalReadController;
use App\Modules\Reservations\Http\Controllers\IcalImportController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/reservations/ical/read', IcalReadController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/reservations/ical/read', IcalReadController::class);
    Route::post('/reservations/ical/import', IcalImportController::class);
});