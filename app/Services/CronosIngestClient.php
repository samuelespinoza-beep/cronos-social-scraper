<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Envia (push) los datos scrapeados al endpoint de ingesta de
 * cronos-analytics-api. Este proyecto NO toca Mongo: cronos recibe el JSON,
 * hace el merge/upsert y persiste. Solo salida HTTPS (funciona detras de NAT).
 */
class CronosIngestClient
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.cronos.ingest_url'), '/');
        $this->token = (string) config('services.cronos.ingest_token');
    }

    /**
     * POST de los datos de una plataforma a cronos.
     * Endpoint destino: {ingest_url}/{platform}
     */
    public function push(string $platform, array $data): void
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('CRONOS_INGEST_URL no esta configurada');
        }

        $response = Http::withToken($this->token)
            ->timeout(30)
            ->post($this->baseUrl . '/' . $platform, $data);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Ingesta cronos [{$platform}] HTTP " . $response->status() . ': '
                . mb_substr($response->body(), 0, 300)
            );
        }
    }
}
