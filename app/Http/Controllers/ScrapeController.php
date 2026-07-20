<?php

namespace App\Http\Controllers;

use App\Services\Facebook\FacebookService;
use App\Services\Instagram\InstagramService;
use App\Services\X\XService;
use App\Services\Youtube\YoutubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scraper por HTTP: un endpoint GET por plataforma (/scrape/{plataforma}).
 * Devuelve el JSON scrapeado tal cual.
 */
class ScrapeController extends Controller
{
    public function __construct(
        private readonly YoutubeService $youtube,
        private readonly XService $x,
        private readonly InstagramService $instagram,
        private readonly FacebookService $facebook,
    ) {
    }

    public function youtube(): JsonResponse
    {
        return $this->runPlatform('youtube', fn () => $this->youtube->sync());
    }

    public function x(): JsonResponse
    {
        return $this->runPlatform('x', fn () => $this->x->sync());
    }

    public function instagram(): JsonResponse
    {
        return $this->runPlatform('instagram', fn () => $this->instagram->sync());
    }

    public function facebook(): JsonResponse
    {
        return $this->runPlatform('facebook', fn () => $this->facebook->sync());
    }

    private function runPlatform(string $platform, callable $sync): JsonResponse
    {
        try {
            $data = $sync();

            return response()->json([
                'platform' => $platform,
                'status' => 'ok',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error("scrape:api [{$platform}]: " . $e->getMessage());

            return response()->json([
                'platform' => $platform,
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
