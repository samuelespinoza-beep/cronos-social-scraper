<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scrapea las redes de la competencia y empuja los datos a cronos cada 30 min.
// withoutOverlapping(): si una corrida se pasa de 30 min, no se encima con la siguiente.
Schedule::command('scrape:run')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scrape.log'));
