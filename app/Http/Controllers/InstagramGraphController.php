<?php

namespace App\Http\Controllers;

use App\Services\Instagram\InstagramGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ruta PARALELA de prueba: /api/scrape/instagram2
 *
 * Usa la Graph API oficial de Meta (business_discovery) en vez del scraping no oficial.
 * NO reemplaza a /api/scrape/instagram (ScrapeController@instagram); convive con el para
 * poder comparar ambos resultados. Cuando la via oficial este validada, se puede jubilar
 * el scraping viejo.
 */
class InstagramGraphController extends Controller
{
    public function __construct(
        private readonly InstagramGraphService $instagram,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        try {
            return response()->json([
                'platform' => 'instagram2',
                'status'   => 'ok',
                'data'     => $this->instagram->sync(),
            ]);
        } catch (Throwable $e) {
            Log::error('scrape:api [instagram2]: ' . $e->getMessage());

            return response()->json([
                'platform' => 'instagram2',
                'status'   => 'error',
                'message'  => $e->getMessage(),
            ], 500);
        }
    }
}
