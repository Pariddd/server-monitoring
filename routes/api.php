<?php

use App\Http\Controllers\Api\IngestEsp32Controller;
use App\Http\Controllers\Api\IngestTelegrafController;
use Illuminate\Support\Facades\Route;

// Urutan: batas per IP (300/menit) → autentikasi token → batas per sumber (120/menit).
Route::middleware('throttle:ingest-ip')->group(function () {
    Route::post('/ingest/telegraf', IngestTelegrafController::class)
        ->middleware(['source.auth:host', 'source.throttle']);

    Route::post('/ingest/esp32', IngestEsp32Controller::class)
        ->middleware(['source.auth:esp32', 'source.throttle']);
});
