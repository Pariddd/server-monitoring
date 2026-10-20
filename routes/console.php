<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('alerts:evaluate')
    ->everyTenSeconds()
    ->withoutOverlapping(1)
    ->appendOutputTo(storage_path('logs/alerts-evaluate.log'));
