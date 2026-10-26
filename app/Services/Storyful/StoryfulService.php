<?php

namespace App\Services\Storyful;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee las ultimas notas de Storyful Newswire con la API JSON interna de la web
 * (la misma que usa newswire.storyful.com en el navegador), usando las cookies
 * de una sesion iniciada. Solo lee informacion: no descarga videos.
 *
 * PRUEBA / DEMO: la via oficial es el feed MRSS (token a support@storyful.com).
 */
class StoryfulService
{
    private const TIMEZONE = 'America/Lima';

    private string $session;
    private string $cfClearance;
    private string $baseUrl;
    private int $detailLimit;

    public function __construct()
    {
        $this->session = (string) config('services.storyful.session');
        $this->cfClearance = (string) config('services.storyful.cf_clearance');
        $this->baseUrl = rtrim((string) config('services.storyful.base_url'), '/') . '/';
        $this->detailLimit = (int) config('services.storyful.detail_limit', 10);
    }

    public function sync(): array
    {
        if ($this->session === '') {
            throw new \RuntimeException('STORYFUL_SESSION no esta configurado');
        }

        // Los mismos parametros que usa el home ("All Stories"): sin
        // exclude_from_home_page salen notas que la pagina no muestra.
        $payload = $this->getJson('api/stories.json', ['page' => 1, 'exclude_from_home_page' => 'true']);

        $stories = [];
        foreach ($payload['stories'] ?? [] as $i => $story) {
            // El detalle trae el video original, el credito y las categorias,
            // pero es una peticion por nota: solo para las mas recientes.
            $detail = null;
            if ($i < $this->detailLimit) {
                try {
                    $detail = $this->getJson("api/stories/{$story['id']}.json");
                } catch (\Throwable $exception) {
                    Log::warning("Storyful: sin detalle para la nota {$story['id']}: " . $exception->getMessage());
                }
            }

            $stories[] = $this->buildStoryDoc($story, $detail);
        }

        return [
            'date'    => now(self::TIMEZONE)->toDateString(),
            'total'   => $payload['total'] ?? null,
            'stories' => $stories,
        ];
    }

    private function headers(): array
    {
        $cookies = ["_storyful_newswire_session={$this->session}"];
        if ($this->cfClearance !== '') {
            $cookies[] = "cf_clearance={$this->cfClearance}";
        }

        return [
            'Cookie' => implode('; ', $cookies),
            'Accept' => 'application/json',
        ];
    }

    private function getJson(string $path, array $query = []): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(20)
            ->get($this->baseUrl . $path, $query);

        $json = $response->json();

        if ($response->failed() || !is_array($json)) {
            throw new \RuntimeException(
                "Storyful {$path} HTTP {$response->status()}: " . $this->describeFailure($response)
            );
        }

        return $json;
    }

    /** Distingue el bloqueo de Cloudflare de una sesion caducada u otro error. */
    private function describeFailure(Response $response): string
    {
        $body = $response->body();

        if (str_contains($body, 'cf-error-details') || str_contains($body, 'challenge-platform')) {
            preg_match('/<title>(.*?)<\/title>/s', $body, $match);

            return 'bloqueado por Cloudflare (' . trim($match[1] ?? 'sin titulo') . ')';
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return 'sesion invalida o caducada; vuelve a copiar las cookies';
        }

        return mb_substr(trim(strip_tags($body)), 0, 200);
    }

    private function buildStoryDoc(array $story, ?array $detail): array
    {
        $lead = $detail['lead_item'] ?? $story['lead_item'] ?? [];

        return [
            'story_id'       => $story['id'] ?? null,
            'title'          => $story['title'] ?? null,
            'slug'           => $story['slug'] ?? null,
            'summary'        => $story['summary'] ?? null,
            'published_at'   => isset($story['published_date'])
                ? Carbon::parse($story['published_date'])->setTimezone(self::TIMEZONE)->toDateTimeString()
                : null,
            'clearance'      => $story['clearance_mark'] ?? null,
            'trending'       => (bool) ($story['in_trending_collection'] ?? false),
            'regions'        => $story['regions'] ?? [],
            // Solo viene en la lista, no en el detalle.
            'resource_type'  => $story['lead_item']['resource_type'] ?? null,
            'thumbnail'      => $story['lead_image']['url'] ?? null,
            'url'            => isset($story['id']) ? $this->baseUrl . 'story/' . $story['id'] : null,

            // Solo con detalle (las primeras detail_limit notas).
            'credit'         => $detail['credits'] ?? null,
            'clearance_text' => $detail['clearance_text_short'] ?? null,
            'location'       => $detail['location_text'] ?? null,
            'categories'     => $detail['categories'] ?? null,
            'source'         => $detail['source_text'] ?? null,
            // Video original (YouTube, X...): de aqui se pueden sacar las vistas.
            'original_url'   => $lead['provider_url'] ?? null,
            'original_type'  => $lead['type'] ?? null,
        ];
    }
}
