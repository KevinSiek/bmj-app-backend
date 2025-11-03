<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SparepartsController;
use App\Http\Controllers\Api\ValidationController;

// Enhanced Spareparts endpoints for selector support
Route::get('/spareparts', [SparepartsController::class, 'index']);
Route::get('/spareparts/low-stock', [SparepartsController::class, 'lowStock']);

// Generic uniqueness validation endpoint
Route::get('/validation/unique', [ValidationController::class, 'unique']);
