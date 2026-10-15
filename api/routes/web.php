<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/login', [AuthController::class, 'loginWeb'])
    ->middleware('throttle:5,1');

Route::post('/logout', [AuthController::class, 'logoutWeb'])
    ->middleware('auth:web');
