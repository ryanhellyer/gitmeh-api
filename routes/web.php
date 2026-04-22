<?php

declare(strict_types=1);

use App\Http\Controllers\GitmehChatCompletionsController;
use App\Http\Controllers\GitmehController;
use App\Http\Controllers\GitmehStatusController;
use Illuminate\Support\Facades\Route;

$apiOnly = fn () => response()->view('api-only');

Route::get('/gitmeh', GitmehStatusController::class);
Route::get('/gitmeh/', GitmehStatusController::class);

Route::post('/gitmeh', GitmehController::class)->middleware('gitmeh.daily');
Route::post('/gitmeh/', GitmehController::class)->middleware('gitmeh.daily');

Route::any('/v1/chat/completions', GitmehChatCompletionsController::class)->middleware([
    'gitmeh.chat_post_only',
    'gitmeh.json_body',
    'gitmeh.optional_bearer',
    'gitmeh.daily',
]);

Route::get('/', $apiOnly);

Route::fallback($apiOnly);
