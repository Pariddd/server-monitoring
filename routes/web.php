<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/servers/{source:name}', [DashboardController::class, 'show'])->name('servers.show');

Route::prefix('data')->name('data.')->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary'])->name('summary');
    Route::get('/servers/{source:name}', [DashboardController::class, 'sourceData'])->name('server');
});
