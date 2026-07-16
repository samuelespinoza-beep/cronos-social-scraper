<?php

namespace App\Services\Facebook;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookService
{
    private const TIMEZONE = 'America/Lima';
    private const DOC_ID = '27635166756168083';
    private const FRIENDLY_NAME = 'ProfileCometTimelineFeedRefetchQuery';
    private const CRN = 'comet.fbweb.CometProfileTimelineListViewRoute';
    private const POSTS_PER_PAGE = 3;
    private const MAX_PAGES = 15;

    private const COMET_STATIC_FIELDS = [
        'av'          => '61586343923018',
        '__aaid'      => '0',
        '__user'      => '61586343923018',
        '__a'         => '1',
        '__req'       => '32',
        '__hs'        => '20649.HYP:comet_pkg.2.1...0',
        'dpr'         => '1',
        '__ccg'       => 'EXCELLENT',
        '__rev'       => '1043180520',
        '__s'         => 'f9ew6k:gp7y9t:vrg4rd',
        '__hsi'       => '7662635218482664760',
        '__dyn'       => '7xeUjGU5a5Q1ryaxG4Vp41twWwIxu13wsoKbgS3q2ibwNw9G2Saw8i2S1DwUx60GE5O0BU2_CxS320qa2OU7m221Fwgo9oO0-E4a3a4oaEnxO0Bo7O2l2Utwqo5W1ywiE4u9x-3m1mzXw8W58jwGzE8FU5e3ym2SU4i5oe8cEW4-5pUfEdK1MBx_wHwdG7EbUG2-azqwt8eo88cA0Lo4q58jyUaUbGxe6Uak0zU8oC1hxB0jUpwgUjz89oeE-5oabDzUiBG2OUqwlqwLwlE7m11zU1fUaUcEK6Eqwby0QUqwRyBxO',
        '__csr'       => 'g5l7NYA9MZ4PMz2dPT6sYvf6Ndh5nNTstrkRRN4jPOYDAEBbdIRiEyldHnGJey4RyaFSKlF2ugWvhkjAVb8p6_rZ-O2QpXnlejkHJ4F--8FlmEDXOJ4FaGriiQAZAVGl4HGtauqhXAZ5gNeqGucAGhqQFWhbGAHh9pX-VCXAsCVqhGz4iinnqRFkVRaWXgExWFZ9FqAhbQHGBLGKh6BKFlEGLADQcCFqHBzGALDxuGAyEOmiFGBULnzWAQi2eayUogzDx67omwRx69zFqyA4rzpF4aAGm8GiexTDxeq2yfzoPw-yEiyEWfK9y8uUOUOUPBxy8y8oCwFwxwzG78vCBy8qK7u9z8G5EtyUcVebyUrKbU8Ubo4i3x0oQ9yUKm3i5Ey8xu1-U5SbDzEixbwyxy78nBy8kxm1kx21iwko6y1uwmE3eAwmeumm2C9wbi3d3oozC3q27zu1rzUK0wofEgyUvwUwzyE7y5EaE9Oe360G64of8ao3ews-3em2e04vo0Nq07S821w3uo1eU48w3ml03LE28c1Bw9W02JJwBg5C04eU5-0dJw0ujU0pUxm013io21w0jtA1OwDwau5E0Oq4m0h5058ylwdC0c8w15e0dowuUG7z1y2m0R203f40gow0BybxKsC04wo0gDyA0CYi085Q4A3a04mE0qoBS0ku2a0C87W02p606KpQho3gg4C0jdw3gE3Ew',
        '__hsdp'      => 'gb212xs5O4cp68ixaicCy4b5cggy4EGcxi4awSAoG5E278wICzj8W5mgqg_i244k44ZmwhNcQj4D79ctq3sychSAkyGOWbDjVV4gob8O4FcGq5iEwUCGbbaImgQ87FB42jcExhN0UggAb9A5EGkiG28w-G4kOgSwwNQCn2bfJJ8gkpsVhiOY9gIGiaURGGhmB8giguAGEbeqJdDgCUWbKt4Gkk4VUK6F4mt5458Adzzypk8OO4zQh1N3AAECdAx2bh4mi1JCjEEhgqSA8GEngb6zyVVoR0kayk39AwywOwhokhEwPCAGczIM-4Q9x6ufyAagiy88E6C6UwM554gKhx28wMCxl28945XBCxm6821wSw5Do2Ny8rgrxXxudwpoxG1Tg9EFaEG1owDwLwCz88EdaBU9A0AE2bw6Mx6i0TUd9lwExy0QoaU28wk836w4pwSwo83cw2085K1lwdS0kO08cwrVo1kE0xK051o5m2u0oe680zy08qw6GwCw961Mx62W14w30833w5mwNw2781f82tw6gw3UUW04e8148bE4a0TE1r80y20JUeE',
        '__hblp'      => '1u4aJ0Tx-9wxy821o5i1Txq2a31362S1cwcy8grwNU8oK1sxS6HxbwcWcwqHAwm89o-dwBwGwBy89EkyE5m1bwVBwJwgEkwpoqyE8o6i2a5oSewhojxqVFVEoxaaxZd1u1mwqory5odrxum4ojDwMCxm9wAgnKmqEiz8Kagd8twywSwmWw49w8O7Voy3t1W6pE6e8y8985u2uax61oxa9xC223e0yo2jwZwjo1ME5K0y8eEaUowlU7C2mm0xQi19UtQi17wm87yfG0zolx2eglz8foaokwgo4K1mw8i2y0zE4i0wU5m360Fpo27wci0SoswBxm311e0AU1pU620CE18U4-cw6Cyo7u1Nw4nwPw46gmwlo9U18k1wxym18wbe1FwIw9W0Fod81fUow6hwCwRwmU724obE4i0OE1W84O0MU2GwaK360UU2ZgmCU9Ucohwu82NwMwTwQwk81fUow4HU10U6C9x62615waG586m3i0HE5u0oO0yE7y2iu0M8jzEgxS12waS58rw5BCwci0WUeEiwxwbu3G',
        '__sjsp'      => 'gb212xs5O4cp68ixaicCy4b5cggy4EGcxi4awSAoG5E7AQy2OqdcP949p1F3Z8884caEjfnE4sjd4N9NOj5Rq3sychSAl2SWbDhoN462ShBGuSaFr8e8ili8F5p88Gyj2i7oZwADxlxt54Gsly4qEigiBorgB2asQFUCV946VFQgE-250zgK9g8UlgggoxWbh89pU9F4dgjg4Awkh4310gAm0BQ250Cgb4l08B0Vwlo5F6y41tg8Uqg1gVk0BUa80Ox05eg',
        '__comet_req' => '15',
        '__spin_r'    => '1043180520',
        '__spin_b'    => 'trunk',
        '__spin_t'    => '1784096290',
        '__crn'       => self::CRN,
        'fb_api_caller_class'      => 'RelayModern',
        'fb_api_req_friendly_name' => self::FRIENDLY_NAME,
        'server_timestamps'        => 'true',
    ];

    private const TRACKING_CODE = 'AZaAogye4CHGaZQRZrMZh99nSoBIInmruxt59k9lcStByuLR2ssIsbrwRX4N5QFV7Y8YeyOrUjTlp7V8XipwuqLoVgV402Ryxfko5dVgROnm-nRWW1dtIlmrCK2mgonlRcooiVT-AEkaR8V5SLFSCKhjZn2OgKP4oz-8QQ7_AKHWvEDomlvJFiFa0ovrxyaN6eOlQojJy4L9IX2TC_uVEz1X';

    private string $cUser;
    private string $xs;
    private string $baseUrl;

    public function __construct()
    {
        $this->cUser = (string) config('services.facebook.c_user');
        $this->xs = (string) config('services.facebook.xs');
        $this->baseUrl = rtrim((string) config('services.facebook.base_url'), '/') . '/';
    }

    public function sync(): array
    {
        if ($this->cUser === '' || $this->xs === '') {
            throw new \RuntimeException('FACEBOOK_C_USER / FACEBOOK_XS no estan configurados');
        }

        $tokens = $this->fetchAppTokens();

        $date = now(self::TIMEZONE)->toDateString();
        $accountsData = [];

        foreach ((array) config('services.facebook.accounts', []) as $key => $account) {
            $pageId = $account['page_id'] ?? null;
            if (!$pageId) {
                Log::warning("Facebook: falta page_id para la cuenta '{$key}', se omite");
                continue;
            }

            try {
                $byId = [];
                foreach ($this->fetchTodaysPosts($pageId, $tokens) as $post) {
                    $byId[$post['post_id']] = $this->buildPostDoc($account, $post);
                }

                $accountsData[$key] = array_values($byId);
            } catch (\Throwable $exception) {
                Log::error("Facebook sync error para '{$key}': " . $exception->getMessage());
            }
        }

        return [
            'date'     => $date,
            'accounts' => $accountsData,
        ];
    }

    private function baseHeaders(): array
    {
        return [
            'Cookie'             => "c_user={$this->cUser}; xs={$this->xs}",
            'Referer'            => $this->baseUrl,
            'User-Agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept-Language'    => 'es-PE,es;q=0.9',
            'sec-ch-ua'          => '"Chromium";v="126", "Google Chrome";v="126", "Not.A/Brand";v="24"',
            'sec-ch-ua-mobile'   => '?0',
            'sec-ch-ua-platform' => '"Windows"',
        ];
    }

    private function documentHeaders(): array
    {
        return array_merge($this->baseHeaders(), [
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'sec-fetch-dest'             => 'document',
            'sec-fetch-mode'             => 'navigate',
            'sec-fetch-site'             => 'none',
            'sec-fetch-user'             => '?1',
            'upgrade-insecure-requests'  => '1',
        ]);
    }

    private function xhrHeaders(): array
    {
        return array_merge($this->baseHeaders(), [
            'x-requested-with' => 'XMLHttpRequest',
            'Accept'           => '*/*',
            'sec-fetch-dest'   => 'empty',
            'sec-fetch-mode'   => 'cors',
            'sec-fetch-site'   => 'same-origin',
        ]);
    }

    private function fetchAppTokens(): array
    {
        $response = Http::withHeaders($this->documentHeaders())
            ->withOptions(['version' => 2.0])
            ->timeout(15)
            ->get($this->baseUrl);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Facebook: no se pudo cargar la pagina para sacar fb_dtsg/lsd, HTTP ' . $response->status()
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
                'Facebook: no se pudieron extraer fb_dtsg/lsd del HTML (el regex quedo desactualizado)'
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

    private function fetchTodaysPosts(string $pageId, array $tokens): array
    {
        $today = now(self::TIMEZONE)->toDateString();
        $posts = [];
        $cursor = null;
        $pages = 0;
        $reachedOlder = false;

        do {
            [$orderedEdges, $nextCursor] = $this->requestFeedPage($pageId, $tokens, $cursor);
            if (empty($orderedEdges)) {
                break;
            }

            foreach ($orderedEdges as $node) {
                $createdAt = $node['creation_time'] ?? null;
                if ($createdAt === null) {
                    continue;
                }

                $day = Carbon::createFromTimestamp($createdAt)->timezone(self::TIMEZONE)->toDateString();
                if ($day < $today) {
                    $reachedOlder = true;
                    break;
                }
                if ($day !== $today) {
                    continue;
                }

                $posts[] = $this->extractPostFields($node, $createdAt);
            }

            $cursor = $reachedOlder ? null : $nextCursor;
        } while ($cursor && ++$pages < self::MAX_PAGES);

        return $posts;
    }

    private function requestFeedPage(string $pageId, array $tokens, ?string $cursor): array
    {
        $fbDtsg = $tokens['fb_dtsg'];
        $lsd = $tokens['lsd'];

        $variables = [
            'afterTime'    => null,
            'beforeTime'   => null,
            'count'        => self::POSTS_PER_PAGE,
            'cursor'       => $cursor,
            'feedLocation' => 'TIMELINE',
            'feedbackSource' => 0,
            'focusCommentID' => null,
            'memorializedSplitTimeFilter' => null,
            'omitPinnedPost' => true,
            'postedBy'     => ['group' => 'OWNER'],
            'privacy'      => null,
            'privacySelectorRenderLocation' => 'COMET_STREAM',
            'referringStoryRenderLocation'  => null,
            'renderLocation' => 'timeline',
            'scale'        => 1,
            'stream_count' => 1,
            'taggedInOnly' => null,
            'trackingCode' => self::TRACKING_CODE,
            'useDefaultActor' => false,
            'id'           => $pageId,
        ];

        $body = array_merge(self::COMET_STATIC_FIELDS, [
            'fb_dtsg'   => $fbDtsg,
            'jazoest'   => $this->computeJazoest($fbDtsg),
            'lsd'       => $lsd,
            'doc_id'    => self::DOC_ID,
            'variables' => json_encode($variables),
        ]);

        $response = Http::withHeaders(array_merge($this->xhrHeaders(), [
                'x-fb-lsd'           => $lsd,
                'x-fb-friendly-name' => self::FRIENDLY_NAME,
            ]))
            ->withOptions(['version' => 2.0])
            ->asForm()
            ->timeout(20)
            ->post($this->baseUrl . 'api/graphql/', $body);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Facebook graphql/query HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500)
            );
        }

        $result = $this->parseIncrementalResponse($response->body());
        if ($result === null) {
            Log::warning('Facebook graphql/query: no se encontro la lista de posts en la respuesta (el shape cambio o fb_dtsg/lsd vencido)', [
                'page_id'  => $pageId,
                'response' => mb_substr($response->body(), 0, 1000),
            ]);
            return [[], null];
        }

        return $result;
    }

    private function parseIncrementalResponse(string $body): ?array
    {
        $lines = explode(PHP_EOL, trim($body));

        $baseEdges = null;
        $nodesByPostId = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if ($baseEdges === null) {
                $baseEdges = $decoded['data']['node']['timeline_list_feed_units']['edges'] ?? null;
            }

            $this->collectNodesByPostId($decoded, $nodesByPostId);
        }

        if (!$baseEdges) {
            return null;
        }

        $lastCursor = null;
        foreach ($baseEdges as $edge) {
            $lastCursor = $edge['cursor'] ?? $lastCursor;
        }

        $orderedNodes = array_values($nodesByPostId);
        usort($orderedNodes, fn ($a, $b) => ($b['creation_time'] ?? 0) <=> ($a['creation_time'] ?? 0));

        return [$orderedNodes, $lastCursor];
    }

    private function collectNodesByPostId($data, array &$out): void
    {
        if (!is_array($data)) {
            return;
        }

        if (isset($data['post_id']) && is_string($data['post_id'])) {
            $id = $data['post_id'];
            $out[$id] = isset($out[$id]) ? array_replace_recursive($out[$id], $data) : $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collectNodesByPostId($value, $out);
            }
        }
    }

    private function extractPostFields(array $node, int $createdAt): array
    {
        $feedback = $node['comet_sections']['feedback']['story']['story_ufi_container']['story']
            ['feedback_context']['feedback_target_with_context']['comet_ufi_summary_and_actions_renderer']['feedback']
            ?? [];

        return [
            'post_id'        => $node['post_id'],
            'caption'        => $node['comet_sections']['content']['story']['message']['text'] ?? null,
            'permalink'      => $node['permalink_url'] ?? null,
            'created_at'     => Carbon::createFromTimestamp($createdAt)->toIso8601String(),
            'reaction_count' => (int) ($feedback['reaction_count']['count'] ?? 0),
            'comment_count'  => (int) ($feedback['comment_rendering_instance']['comments']['total_count'] ?? 0),
            'share_count'    => (int) ($feedback['share_count']['count'] ?? 0),
        ];
    }

    private function buildPostDoc(array $account, array $post): array
    {
        return [
            'post_id'   => $post['post_id'],
            'handle'    => $account['handle'] ?? null,
            'caption'   => $post['caption'],
            'created_at'=> $post['created_at'],
            'permalink' => $post['permalink'] ?? ('https://www.facebook.com/' . ($account['page_id'] ?? '')),
            'metrics'   => [
                'captured_at'    => now()->toIso8601String(),
                'reaction_count' => $post['reaction_count'],
                'comment_count'  => $post['comment_count'],
                'share_count'    => $post['share_count'],
            ],
        ];
    }
}
