<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SiteController::class, 'home']);
Route::get('/favicon.svg', [SiteController::class, 'favicon']);
Route::get('/healthz', HealthController::class);
