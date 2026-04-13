<?php

use App\Http\Controllers\GitmehController;
use Illuminate\Support\Facades\Route;

$apiOnly = fn () => response()->view('api-only');

Route::post('/gitmeh', GitmehController::class);
Route::post('/gitmeh/', GitmehController::class);

Route::get('/', $apiOnly);

Route::fallback($apiOnly);
