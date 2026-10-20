<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RuleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/servers/{source:name}', [DashboardController::class, 'show'])->name('servers.show');

Route::get('/rules', [RuleController::class, 'edit'])->name('rules.edit');
Route::put('/rules', [RuleController::class, 'update'])->name('rules.update');
Route::post('/rules/reset', [RuleController::class, 'reset'])->name('rules.reset');

Route::prefix('data')->name('data.')->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary'])->name('summary');
    Route::get('/servers/{source:name}', [DashboardController::class, 'sourceData'])->name('server');
});
