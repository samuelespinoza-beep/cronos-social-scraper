<?php

namespace App\Services\Viory;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee los ultimos videos de Viory con la API JSON interna de la web
 * (la misma que usa viory.video en el navegador). Es publica: no necesita
 * sesion ni cookies. Solo lee informacion: no descarga videos (cada descarga
 * consume la cuota del contrato de GLR).
 */
class VioryService
{
    private const TIMEZONE = 'America/Lima';

    private string $baseUrl;
    private string $locale;
    private int $perPage;
    private int $detailLimit;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.viory.base_url'), '/') . '/';
        $this->locale = (string) config('services.viory.locale', 'en');
        $this->perPage = (int) config('services.viory.per_page', 20);
        $this->detailLimit = (int) config('services.viory.detail_limit', 10);
    }

    public function sync(): array
    {
        // En "en" esta el catalogo completo; en "es" solo los ya traducidos,
        // y los mas nuevos tardan en aparecer.
        $payload = $this->getJson('api/content/video_projects', [
            'locale'   => $this->locale,
            'page'     => 1,
            'per_page' => $this->perPage,
        ]);

        $videos = [];
        foreach ($payload['videos'] ?? [] as $i => $video) {
            // El detalle trae la descripcion, los shots, el precio y las
            // traducciones, pero es una peticion por video: solo los mas recientes.
            $detail = null;
            if ($i < $this->detailLimit) {
                try {
                    $detail = $this->getJson("api/content/video_projects/{$video['id']}", ['locale' => $this->locale]);
                } catch (\Throwable $exception) {
                    Log::warning("Viory: sin detalle para el video {$video['id']}: " . $exception->getMessage());
                }
            }

            $videos[] = $this->buildVideoDoc($video, $detail);
        }

        return [
            'date'   => now(self::TIMEZONE)->toDateString(),
            'total'  => $payload['count'] ?? null,
            'videos' => $videos,
        ];
    }

    private function getJson(string $path, array $query = []): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout(20)
            ->get($this->baseUrl . $path, $query);

        $json = $response->json();

        if ($response->failed() || !is_array($json)) {
            throw new \RuntimeException(
                "Viory {$path} HTTP {$response->status()}: " . mb_substr(trim(strip_tags($response->body())), 0, 200)
            );
        }

        return $json;
    }

    private function buildVideoDoc(array $video, ?array $detail): array
    {
        $es = $this->translation($detail, 'es');

        return [
            'video_id'      => $video['id'] ?? null,
            'title'         => $video['title'] ?? null,
            'location'      => $video['location'] ?? null,
            'country'       => $video['country_name'] ?? null,
            'tags'          => array_column($video['tags'] ?? [], 'name'),
            'published_at'  => $this->localTime($video['published_at'] ?? null),
            'updated_at'    => $this->localTime($video['last_changed_at'] ?? null),
            'duration_sec'  => isset($video['duration']) ? (int) round($video['duration'] / 1000) : null,
            'format'        => $video['format'] ?? null,
            // pool = material oficial (gobiernos), ilimitado en el contrato;
            // regular_content descuenta de la cuota.
            'limit_type'    => $video['aggregated_limit_type'] ?? $video['limit_type'] ?? null,
            'rights_type'   => $video['rights_type'] ?? null,
            'restrictions'  => $video['restrictions'] ?? null,
            'downloadable'  => ($video['downloadable'] ?? null) === 'enabled',
            'ai_generated'  => (bool) ($video['ai_generated'] ?? false),
            'thumbnail'     => $video['selected_preview_image']['large']['url'] ?? null,
            'preview_mp4'   => $video['preview_file_url'] ?? null,
            'url'           => isset($video['id']) ? $this->baseUrl . "{$this->locale}/videos/{$video['id']}" : null,

            // Solo con detalle (los primeros detail_limit videos).
            'description'   => $detail['description'] ?? null,
            'shots'         => $detail['shots'] ?? null,
            'region'        => $detail['region_name'] ?? null,
            'ratio'         => $detail['ratio'] ?? null,
            'clean_price'   => isset($detail['clean_price']) ? (float) $detail['clean_price'] : null,
            'branded_price' => isset($detail['branded_price']) ? (float) $detail['branded_price'] : null,
            'preview_hls'   => $detail['preview_url'] ?? null,
            // Traduccion al espanol de Viory (a veces hecha con IA).
            'title_es'          => $es['title'] ?? null,
            'description_es'    => $es['description'] ?? null,
            'es_ai_translated'  => isset($es) ? (bool) ($es['ai_generated'] ?? false) : null,
        ];
    }

    private function translation(?array $detail, string $locale): ?array
    {
        foreach ($detail['translations'] ?? [] as $translation) {
            if (($translation['locale'] ?? null) === $locale) {
                return $translation;
            }
        }

        return null;
    }

    private function localTime(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->setTimezone(self::TIMEZONE)->toDateTimeString() : null;
    }
}
