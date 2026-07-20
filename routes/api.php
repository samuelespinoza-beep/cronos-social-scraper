<?php

use App\Http\Controllers\ScrapeController;
use Illuminate\Support\Facades\Route;

Route::get('/scrape/youtube', [ScrapeController::class, 'youtube']);
Route::get('/scrape/x', [ScrapeController::class, 'x']);
Route::get('/scrape/instagram', [ScrapeController::class, 'instagram']);
Route::get('/scrape/facebook', [ScrapeController::class, 'facebook']);
