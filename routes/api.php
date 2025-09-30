<?php

use App\Http\Controllers\Api\FigmaFetchNodesWithImageController;
use App\Http\Controllers\Api\TailwindConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('figma')->group(function() {
    Route::post('/tailwind-config', [TailwindConfigController::class, 'generate'])->name('tailwind-config.generate');
    Route::post('/fetch-nodes-with-image', [FigmaFetchNodesWithImageController::class, 'generate'])->name('fetch-nodes-with-image.generate');
});
