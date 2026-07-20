<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookService;
use App\Services\Instagram\InstagramService;
use App\Services\X\XService;
use App\Services\Youtube\YoutubeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el scraping de cada plataforma.
 * Lo dispara el cron de la PC cada 30 min:  php artisan scrape:run
 *
 *   php artisan scrape:run                     -> todas las plataformas
 *   php artisan scrape:run --platform=youtube  -> solo una
 */
class ScrapeCommand extends Command
{
    protected $signature = 'scrape:run {--platform=all : youtube|x|instagram|facebook|all}';
    protected $description = 'Scrapea las redes de la competencia';

    public function __construct(
        private readonly YoutubeService $youtube,
        private readonly XService $x,
        private readonly InstagramService $instagram,
        private readonly FacebookService $facebook,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $platform = (string) $this->option('platform');
        $all = ['youtube', 'x', 'instagram', 'facebook'];
        $targets = $platform === 'all' ? $all : [$platform];

        foreach ($targets as $p) {
            try {
                $data = match ($p) {
                    'youtube'   => $this->youtube->sync(),
                    'x'         => $this->x->sync(),
                    'instagram' => $this->instagram->sync(),
                    'facebook'  => $this->facebook->sync(),
                    default     => null,
                };

                if ($data === null) {
                    $this->warn("Plataforma no soportada: {$p}");
                    continue;
                }

                $this->info("[{$p}] capturado.");
            } catch (\Throwable $e) {
                $this->error("[{$p}] error: " . $e->getMessage());
                Log::error("scrape:run [{$p}]: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
