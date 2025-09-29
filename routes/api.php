<?php

use App\Http\Controllers\Figma\TailwindConfigController;
use Illuminate\Support\Facades\Route;

Route::post('/figma/tailwind-config', [TailwindConfigController::class, 'generate']);
