<?php

use App\Http\Controllers\GitmehController;
use App\Http\Controllers\GitmehStatusController;
use Illuminate\Support\Facades\Route;

$apiOnly = fn () => response()->view('api-only');

Route::get('/gitmeh', GitmehStatusController::class);
Route::get('/gitmeh/', GitmehStatusController::class);

Route::post('/gitmeh', GitmehController::class)->middleware('gitmeh.daily');
Route::post('/gitmeh/', GitmehController::class)->middleware('gitmeh.daily');

Route::get('/', $apiOnly);

Route::fallback($apiOnly);
