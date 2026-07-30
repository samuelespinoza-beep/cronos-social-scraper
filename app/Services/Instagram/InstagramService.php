<?php

namespace App\Services\Instagram;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService
{
    private const TIMEZONE = 'America/Lima';
    private const DOC_ID = '36843822688595799';
    private const FRIENDLY_NAME = 'PolarisProfilePostsTabContentQuery_connection';

    // Provider variables que Instagram exige en la consulta. Igual que en FB,
    // pueden cambiar; el sanador las recaptura a storage/app/instagram-graphql.json.
    private const RELAY_PROVIDERS = [
        '__relay_internal__pv__PolarisMultiCaptionCarouselEnabledrelayprovider'   => false,
        '__relay_internal__pv__PolarisImmersiveFeedChainingEnabledrelayprovider'  => true,
        '__relay_internal__pv__PolarisAIGMAccountLabelEnabledrelayprovider'       => false,
        '__relay_internal__pv__PolarisReelsRecoDebugOverlayEnabledrelayprovider'  => false,
    ];

    private const COMET_STATIC_FIELDS = [
        'av'          => '17841441194201451',
        '__d'         => 'www',
        '__user'      => '0',
        '__a'         => '1',
        '__req'       => 'n',
        '__hs'        => '20648.HYP:instagram_web_pkg.2.1...0',
        'dpr'         => '1',
        '__ccg'       => 'EXCELLENT',
        '__rev'       => '1043132952',
        '__s'         => 'v96x8p:bokctl:15y0xt',
        '__hsi'       => '7662468969573601791',
        '__dyn'       => '7xeUjG1mxu1syUbFp41twpUnwgU7SbzEdF8aUco2qwJxS0k24o0B-q1ew6ywaq0yE462mcw5Mx62G5UswoEcE7O2l0Fwqo5W1yw9O1lwxwQzXwae4UaEW2G0AEco5G0zK5o4q0HU1wEbUGdwtUeo9UaQ0Lo6-bwHwKG1pg2fwxyo6O1FwlAcwBwUQp1yU426V8aUuwm8jxK0-8KmUhw4rwXyEcE4y16wAwj83KwRyrg',
        '__csr'       => 'gH6MhggN4Jl8QT4pyY-HW8BHS4LqZd8NddHEGX5AyJaJqJySn-WSiGX_y4jCypQqoCiK9rDmBiAQBdlO8CuQGl9KimQZ3ZKahykQVrDx3_holuz4XG_C-9uJB9-mFmFFljyokUWbyWByFoiy8pUC5oapHDXKvyGxa6GgJrAAWxqVQUyqGDVlQKF8CUy9XybDz9VembwMy9ogGXzElixB3UgzrCGJozCwUw8q0s201t2w18K00ZEEK1dwjFo0US0UoCbBo1K408QwbK3yii07GE1oU7V3U4e0sCoo0TE1iU1N4q8gKOwmO5ypXBVXw8-8zSfCudwLwa20coxO3xw0mKE0dyoCbCgd88UfE7p00zrwemp0cq',
        '__hsdp'      => 'g8Q7YoJMpcExCilFi4YpIzOd9l4gUtpA8wWlz1zCgh44yq41IwwwO2i1Eg5x2pppi1i3W6Hgyi1lwlbpQUnyODhEmwpHxm3K7o9UW2a19wiEdoborAwv8c83hBxX-17guxy4VEK5EhwgEmQ1WBwbO0b8xu1fw4Kw2aE4C045Q04l83QU5e0jW780Mqdw4Nw2fk0ja0ayw6Ac2yu2S1Dw55wbHw',
        '__hblp'      => '0BxW7E42686S7E422279888G1BUnz8OEc85K6GDz8d49x29zo4-aF0j5mbjy8WbzpomwAwCxGUDyoaUFe7o9UW2ai5EtzUKm4E9odobokCAwhpErCz94ayEkwd6m7LU4t1W4pEjAUK5A484a5J2U4O3m2O0wo1Xo-0C8comg4-0iW0rm1Qwio1Z80y9047AG0bJwlE3ewBU4Oq1uwAw863y4Uy2Oq18U0Eudw4ByUC08qxx04Ow7OwkEgwiUd8eE3UwXc2x6wJK1zw961vwkU9e0A-',
        '__sjsp'      => 'g8Q7YoJb3Y8cExCjCkxf6r8Yzimx4e7mp0gRoWcxAg',
        '__comet_req' => '7',
        '__spin_r'    => '1043132952',
        '__spin_b'    => 'trunk',
        '__spin_t'    => '1784057582',
        '__crn'       => 'comet.igweb.PolarisProfilePostsTabRoute',
        'qpl_active_flow_ids'      => '767108973',
        'fb_api_caller_class'      => 'RelayModern',
        'fb_api_req_friendly_name' => self::FRIENDLY_NAME,
        'server_timestamps'        => 'true',
        'fb_api_analytics_tags'    => '["qpl_active_flow_ids=767108973"]',
    ];

    private string $sessionId;
    private string $csrfToken;
    private string $dsUserId;
    private string $appId;
    private string $baseUrl;

    // Valores de la consulta GraphQL: hardcodeados como respaldo, sobreescritos
    // por el sanador (storage/app/instagram-graphql.json).
    private string $docId;
    private string $rev;
    private string $hs;
    private array $relayProviders;

    public function __construct()
    {
        $this->sessionId = (string) config('services.instagram.session_id');
        $this->csrfToken = (string) config('services.instagram.csrf_token');
        $this->dsUserId = (string) config('services.instagram.ds_user_id');
        $this->appId = (string) config('services.instagram.app_id');
        $this->baseUrl = rtrim((string) config('services.instagram.base_url'), '/') . '/';
        $this->loadGraphqlMeta();
    }

    /**
     * Carga los valores de la consulta GraphQL. Respaldo hardcodeado + overrides
     * frescos del sanador si el JSON existe.
     */
    private function loadGraphqlMeta(): void
    {
        $this->docId = self::DOC_ID;
        $this->rev = self::COMET_STATIC_FIELDS['__rev'];
        $this->hs = self::COMET_STATIC_FIELDS['__hs'];
        $this->relayProviders = self::RELAY_PROVIDERS;

        $path = storage_path('app/instagram-graphql.json');
        if (!is_file($path)) {
            return;
        }

        $meta = json_decode((string) file_get_contents($path), true);
        if (!is_array($meta)) {
            return;
        }

        if (!empty($meta['doc_id'])) {
            $this->docId = (string) $meta['doc_id'];
        }
        if (!empty($meta['rev'])) {
            $this->rev = (string) $meta['rev'];
        }
        if (!empty($meta['hs'])) {
            $this->hs = (string) $meta['hs'];
        }
        if (!empty($meta['providers']) && is_array($meta['providers'])) {
            $this->relayProviders = $meta['providers'];
        }
    }

    /**
     * Dispara el sanador (recaptura doc_id + variables desde el navegador 9222).
     */
    private function healQuery(): void
    {
        Artisan::call('instagram:refresh-query');
    }

    public function sync(): array
    {
        if ($this->sessionId === '' || $this->csrfToken === '' || $this->dsUserId === '') {
            throw new \RuntimeException('INSTAGRAM_SESSION_ID / INSTAGRAM_CSRF_TOKEN / INSTAGRAM_DS_USER_ID no estan configurados');
        }

        $date = now(self::TIMEZONE)->toDateString();

        try {
            $tokens = $this->fetchAppTokens();
            $accountsData = $this->scrapeAllAccounts($tokens);
        } catch (StaleGraphqlQueryException $e) {
            // AUTO-ARREGLO: el doc_id/variables caducaron. Recapturamos y reintentamos.
            Log::warning('Instagram: doc_id/variables caducados. Ejecutando auto-arreglo (sanador)...');
            $this->healQuery();
            $this->loadGraphqlMeta();
            $tokens = $this->fetchAppTokens();
            $accountsData = $this->scrapeAllAccounts($tokens);
        }

        return [
            'date'     => $date,
            'accounts' => $accountsData,
        ];
    }

    /**
     * Recorre todas las cuentas. Errores comunes por cuenta se loguean; un
     * StaleGraphqlQueryException se propaga para disparar el auto-arreglo.
     */
    private function scrapeAllAccounts(array $tokens): array
    {
        $accountsData = [];

        foreach ((array) config('services.instagram.accounts', []) as $key => $handle) {
            try {
                // El graphql/query de posts ya recibe el username en variables, asi que
                // no dependemos de web_profile_info (endpoint que Meta rompe seguido).
                $user = ['username' => ltrim($handle, '@')];

                $byId = [];
                foreach ($this->fetchTodaysPosts($handle, $tokens) as $post) {
                    $byId[$post['post_id']] = $this->buildPostDoc($user, $post);
                }

                $accountsData[$key] = array_values($byId);
            } catch (StaleGraphqlQueryException $e) {
                throw $e; // NO lo tragamos: dispara el auto-arreglo en sync()
            } catch (\Throwable $exception) {
                Log::error("Instagram sync error para '{$key}': " . $exception->getMessage());
            }
        }

        return $accountsData;
    }

    private function headers(): array
    {
        return [
            'x-ig-app-id'       => $this->appId,
            'Cookie'            => "sessionid={$this->sessionId}; csrftoken={$this->csrfToken}; ds_user_id={$this->dsUserId}",
            'x-csrftoken'       => $this->csrfToken,
            'x-requested-with'  => 'XMLHttpRequest',
            'Referer'           => $this->baseUrl,
            'User-Agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'            => '*/*',
            'Accept-Language'   => 'es-PE,es;q=0.9',
        ];
    }

    private function fetchAppTokens(): array
    {
        $response = Http::withHeaders($this->headers())
            ->withOptions(['version' => 2.0])
            ->timeout(15)
            ->get($this->baseUrl);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Instagram: no se pudo cargar la pagina para sacar fb_dtsg/lsd, HTTP ' . $response->status()
            );
        }

        $html = $response->body();

        $fbDtsg = $this->extractToken($html, [
            '/"DTSGInitialData"\s*,\s*\[\]\s*,\s*\{"token":"([^"]+)"/',
            '/"DTSGInitData"\s*,\s*\[\]\s*,\s*\{"token":"([^"]+)"/',
            '/"f_dtsg"\s*:\s*"([^"]+)"/',
            '/name="fb_dtsg"\s+value="([^"]+)"/',
        ]);

        $lsd = $this->extractToken($html, [
            '/"LSD"\s*,\s*\[\]\s*,\s*\{"token":"([^"]+)"/',
            '/"lsd"\s*:\s*\{"token":"([^"]+)"\}/',
            '/name="lsd"\s+value="([^"]+)"/',
        ]);

        if (!$fbDtsg || !$lsd) {
            throw new \RuntimeException(
                'Instagram: no se pudieron extraer fb_dtsg/lsd del HTML (el regex quedo desactualizado, Meta cambio el bundle)'
            );
        }

        return ['fb_dtsg' => $fbDtsg, 'lsd' => $lsd];
    }

    private function extractToken(string $html, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function computeJazoest(string $fbDtsg): string
    {
        $sum = 0;
        foreach (str_split($fbDtsg) as $char) {
            $sum += ord($char);
        }

        return '2' . $sum;
    }

    private function fetchTodaysPosts(string $handle, array $tokens): array
    {
        $today = now(self::TIMEZONE)->toDateString();
        $fbDtsg = $tokens['fb_dtsg'];
        $lsd = $tokens['lsd'];

        $variables = [
            'after' => null,
            'before' => null,
            'data' => [
                'count'                              => 12,
                'include_reel_media_seen_timestamp'  => true,
                'include_relationship_info'          => true,
                'latest_besties_reel_media'          => true,
                'latest_reel_media'                  => true,
            ],
            'first'                  => 12,
            'include_multi_captions' => false,
            'last'     => null,
            'username' => ltrim($handle, '@'),
        ];

        // Provider variables (de instancia: frescas del sanador o de respaldo).
        $variables = array_merge($variables, $this->relayProviders);

        $body = array_merge(self::COMET_STATIC_FIELDS, [
            '__rev'    => $this->rev,
            '__spin_r' => $this->rev,
            '__hs'     => $this->hs,
            'fb_dtsg'  => $fbDtsg,
            'jazoest'  => $this->computeJazoest($fbDtsg),
            'lsd'      => $lsd,
            'doc_id'   => $this->docId,
            'variables' => json_encode($variables),
        ]);

        $response = Http::withHeaders(array_merge($this->headers(), [
                'x-fb-lsd'           => $lsd,
                'x-fb-friendly-name' => self::FRIENDLY_NAME,
            ]))
            ->withOptions(['version' => 2.0])
            ->asForm()
            ->timeout(15)
            ->post($this->baseUrl . 'graphql/query', $body);

        if ($response->failed()) {
            // Un fallo aqui (tipicamente HTTP 400) casi siempre significa que el
            // doc_id fue retirado por Instagram -> disparamos el auto-arreglo.
            Log::warning('Instagram graphql/query HTTP ' . $response->status() . ' (posible doc_id caducado)', [
                'handle'   => $handle,
                'response' => mb_substr($response->body(), 0, 300),
            ]);
            throw new StaleGraphqlQueryException(
                'Instagram graphql/query HTTP ' . $response->status() . ' (doc_id/variables caducados)'
            );
        }

        $data = $response->json('data');
        if (!$data) {
            // Sin "data" = doc_id/variables caducados (o sesion vencida). Disparamos
            // el auto-arreglo; si tras sanar sigue fallando, propagara el error.
            Log::warning('Instagram graphql/query: respuesta sin "data" (doc_id caducado o sesion vencida)', [
                'handle'   => $handle,
                'response' => mb_substr($response->body(), 0, 500),
            ]);
            throw new StaleGraphqlQueryException('Instagram: respuesta sin data (doc_id/variables caducados)');
        }

        $edges = $this->extractEdges($data);
        if ($edges === null) {
            Log::warning('Instagram graphql/query: no se encontro la lista de posts en la respuesta (el shape cambio)', [
                'handle'    => $handle,
                'data_keys' => array_keys($data),
            ]);
            return [];
        }

        return $this->parseEdges($edges, $today);
    }

    private function extractEdges(array $data): ?array
    {
        foreach ($data as $value) {
            if (is_array($value) && isset($value['edges']) && is_array($value['edges'])) {
                return $value['edges'];
            }
        }

        return null;
    }

    private function parseEdges(array $edges, string $today): array
    {
        $posts = [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? null;
            if (!$node) {
                continue;
            }

            $takenAt = $node['taken_at'] ?? $node['taken_at_timestamp'] ?? null;
            if ($takenAt === null) {
                continue;
            }

            $day = Carbon::createFromTimestamp($takenAt)->timezone(self::TIMEZONE)->toDateString();
            if ($day !== $today) {
                continue;
            }

            $caption = $node['caption']['text']
                ?? ($node['edge_media_to_caption']['edges'][0]['node']['text'] ?? null);

            $thumbnail = $node['image_versions2']['candidates'][0]['url']
                ?? $node['display_url']
                ?? $node['thumbnail_src']
                ?? null;

            $posts[] = [
                'post_id'       => $node['id'] ?? ($node['pk'] ?? null),
                'shortcode'     => $node['code'] ?? ($node['shortcode'] ?? null),
                'caption'       => $caption,
                'taken_at'      => Carbon::createFromTimestamp($takenAt)->toIso8601String(),
                'is_video'      => (bool) ($node['is_video'] ?? (($node['media_type'] ?? null) === 2)),
                'thumbnail'     => $thumbnail,
                'like_count'    => (int) ($node['like_count'] ?? ($node['edge_liked_by']['count'] ?? 0)),
                'comment_count' => (int) ($node['comment_count'] ?? ($node['edge_media_to_comment']['count'] ?? 0)),
                'view_count'    => (int) ($node['play_count'] ?? ($node['video_view_count'] ?? 0)),
            ];
        }

        return $posts;
    }

    private function buildPostDoc(array $user, array $post): array
    {
        return [
            'post_id'   => $post['post_id'],
            'username'  => $user['username'],
            'caption'   => $post['caption'],
            'taken_at'  => $post['taken_at'],
            'permalink' => 'https://www.instagram.com/p/' . $post['shortcode'] . '/',
            'thumbnail' => $post['thumbnail'],
            'metrics'   => [
                'captured_at'   => now()->toIso8601String(),
                'like_count'    => $post['like_count'],
                'comment_count' => $post['comment_count'],
                'view_count'    => $post['view_count'],
            ],
        ];
    }
}
