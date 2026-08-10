<?php

namespace App\Services\Instagram;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Version OFICIAL (Graph API de Meta) del scraping de Instagram, usando el edge
 * business_discovery. NO usa cookies, ni el navegador 9222, ni el "sanador".
 *
 * Convive EN PARALELO con InstagramService (el scraping no oficial). Se expone por
 * la ruta /api/scrape/instagram2 para poder compararlos sin romper nada de lo actual.
 *
 * Requiere en .env:
 *   META_PAGE_TOKEN     -> Page Access Token de larga duracion (ver GUIA-GRAPH-API-META.md)
 *   META_IG_BUSINESS_ID -> ID de TU cuenta IG Business (la que hace las consultas)
 *
 * Reutiliza la misma lista de cuentas objetivo que el servicio viejo
 * (config services.instagram.accounts: elcomercio, rpp, infobae).
 */
class InstagramGraphService
{
    private const TIMEZONE = 'America/Lima';
    private const MEDIA_LIMIT = 25;

    private string $baseUrl;
    private string $token;
    private string $igBusinessId;

    public function __construct()
    {
        $version = (string) config('services.meta.graph_version', 'v21.0');
        $this->baseUrl = 'https://graph.facebook.com/' . $version . '/';
        $this->token = (string) config('services.meta.page_token');
        $this->igBusinessId = (string) config('services.meta.ig_business_id');
    }

    public function sync(): array
    {
        if ($this->token === '' || $this->igBusinessId === '') {
            throw new \RuntimeException(
                'META_PAGE_TOKEN / META_IG_BUSINESS_ID no estan configurados en el .env (ver GUIA-GRAPH-API-META.md)'
            );
        }

        $date = now(self::TIMEZONE)->toDateString();
        $accountsData = [];

        foreach ((array) config('services.instagram.accounts', []) as $key => $handle) {
            try {
                $media = $this->fetchMedia(ltrim((string) $handle, '@'));
                $accountsData[$key] = array_map(fn ($m) => $this->buildPostDoc($handle, $m), $media);
            } catch (\Throwable $e) {
                Log::error("InstagramGraph sync error para '{$key}': " . $e->getMessage());
                $accountsData[$key] = [];
            }
        }

        return [
            'date'     => $date,
            'accounts' => $accountsData,
        ];
    }

    /**
     * Consulta los medios publicos recientes de una cuenta Business/Creator ajena
     * (El Comercio, RPP, Infobae) via business_discovery.
     *
     * NOTA: aqui NO se filtra por "hoy" a proposito, para que sea facil verificar que
     * la API trae datos. En produccion se puede filtrar por fecha como el servicio viejo.
     */
    private function fetchMedia(string $username): array
    {
        $fields = sprintf(
            'business_discovery.username(%s){username,followers_count,media_count,'
            . 'media.limit(%d){id,caption,media_type,permalink,timestamp,'
            . 'like_count,comments_count,media_url,thumbnail_url}}',
            $username,
            self::MEDIA_LIMIT
        );

        $response = Http::timeout(20)->get($this->baseUrl . $this->igBusinessId, [
            'fields'       => $fields,
            'access_token' => $this->token,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'business_discovery HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500)
            );
        }

        return (array) $response->json('business_discovery.media.data', []);
    }

    /**
     * Mismo shape que InstagramService::buildPostDoc() para que ambas rutas sean
     * comparables 1:1.
     */
    private function buildPostDoc(string $handle, array $m): array
    {
        return [
            'post_id'    => $m['id'] ?? null,
            'username'   => ltrim($handle, '@'),
            'caption'    => $m['caption'] ?? null,
            'media_type' => $m['media_type'] ?? null,
            'taken_at'   => isset($m['timestamp']) ? Carbon::parse($m['timestamp'])->toIso8601String() : null,
            'permalink'  => $m['permalink'] ?? null,
            'thumbnail'  => $m['thumbnail_url'] ?? ($m['media_url'] ?? null),
            'metrics'    => [
                'captured_at'   => now()->toIso8601String(),
                'like_count'    => (int) ($m['like_count'] ?? 0),
                'comment_count' => (int) ($m['comments_count'] ?? 0),
                // business_discovery no expone view_count de cuentas ajenas.
                'view_count'    => 0,
            ],
        ];
    }
}
