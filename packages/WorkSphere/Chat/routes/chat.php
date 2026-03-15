<?php

use Illuminate\Support\Facades\Route;
use WorkSphere\Chat\Http\Controllers\ChatController;

Route::prefix('api/pkg/chat')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('/', [ChatController::class, 'index']);
    Route::get('/seed-lab', [ChatController::class, 'seedLab']);
    Route::post('/{publicId}/join', [ChatController::class, 'join']);
    Route::get('/{publicId}', [ChatController::class, 'show']);
    Route::post('/{publicId}/send', [ChatController::class, 'send']);
});
