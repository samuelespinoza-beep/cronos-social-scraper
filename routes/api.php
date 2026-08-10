<?php

use App\Http\Controllers\InstagramGraphController;
use App\Http\Controllers\ScrapeController;
use Illuminate\Support\Facades\Route;

Route::get('/scrape/youtube', [ScrapeController::class, 'youtube']);
Route::get('/scrape/x', [ScrapeController::class, 'x']);
Route::get('/scrape/instagram', [ScrapeController::class, 'instagram']);
// Facebook DESHABILITADO temporalmente: la cuenta fue marcada por Meta por scraping.
// Reactivar (descomentar aqui y en ScrapeController) cuando corresponda.
// Route::get('/scrape/facebook', [ScrapeController::class, 'facebook']);

// Via OFICIAL (Graph API / business_discovery), en paralelo al scraping de arriba.
Route::get('/scrape/instagram2', InstagramGraphController::class);
