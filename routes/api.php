<?php

use App\Http\Controllers\Api\IngestEsp32Controller;
use App\Http\Controllers\Api\IngestTelegrafController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:ingest-ip')->group(function () {
  Route::post('/ingest/telegraf', IngestTelegrafController::class)
    ->middleware(['source.auth:host', 'throttle:ingest-source']);

  Route::post('/ingest/esp32', IngestEsp32Controller::class)
    ->middleware(['source.auth:esp32', 'throttle:ingest-source']);
});
