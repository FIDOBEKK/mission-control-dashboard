<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');
Route::get('/api/mission', [DashboardController::class, 'mission'])->name('dashboard.mission');
