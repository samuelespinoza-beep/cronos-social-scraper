<?php

namespace App\Services\Youtube;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeService
{
    private const CHANNEL_CACHE_PREFIX = 'youtube_channel_';
    private const CHANNEL_CACHE_TTL_HOURS = 24;
    private const TIMEZONE = 'America/Lima';
    private const MAX_PAGES = 10;

    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.youtube.api_key');
        $this->baseUrl = rtrim((string) config('services.youtube.base_url'), '/') . '/';
    }

    public function sync(bool $force = false): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('YOUTUBE_API_KEY no esta configurada');
        }

        $date = now(self::TIMEZONE)->toDateString();
        $channelsData = [];

        foreach ((array) config('services.youtube.channels', []) as $key => $ref) {
            try {
                if ($force) {
                    Cache::forget(self::CHANNEL_CACHE_PREFIX . md5($ref));
                }

                $channel = $this->resolveChannel($ref);
                if ($channel === null) {
                    Log::warning("YouTube: no se pudo resolver el canal '{$ref}' (key: {$key})");
                    continue;
                }

                $byId = [];
                foreach ($this->fetchTodaysVideos($channel) as $video) {
                    $byId[$video['video_id']] = $this->buildVideoDoc($channel, $video);
                }

                $channelsData[$key] = array_values($byId);
            } catch (\Throwable $exception) {
                Log::error("YouTube sync error para '{$key}': " . $exception->getMessage());
            }
        }

        return [
            'date'     => $date,
            'channels' => $channelsData,
        ];
    }

    private function resolveChannel(string $ref): ?array
    {
        return Cache::remember(
            self::CHANNEL_CACHE_PREFIX . md5($ref),
            now()->addHours(self::CHANNEL_CACHE_TTL_HOURS),
            function () use ($ref) {
                $params = [
                    'part' => 'id,snippet,contentDetails',
                    'key'  => $this->apiKey,
                ];

                if (str_starts_with($ref, 'UC')) {
                    $params['id'] = $ref;
                } else {
                    $params['forHandle'] = ltrim($ref, '@');
                }

                $response = Http::timeout(15)->get($this->baseUrl . 'channels', $params);

                if ($response->failed()) {
                    throw new \RuntimeException(
                        'YouTube channels.list HTTP ' . $response->status() . ': ' . $response->body()
                    );
                }

                $item = $response->json('items.0');
                if (!$item) {
                    return null;
                }

                return [
                    'channel_id'       => $item['id'],
                    'title'            => $item['snippet']['title'] ?? null,
                    'uploads_playlist' => $item['contentDetails']['relatedPlaylists']['uploads'] ?? null,
                ];
            }
        );
    }

    private function fetchTodaysVideos(array $channel): array
    {
        $uploadsPlaylistId = $channel['uploads_playlist'] ?? null;
        if (!$uploadsPlaylistId) {
            return [];
        }

        $today = now(self::TIMEZONE)->toDateString();
        $todayIds = [];
        $pageToken = null;
        $pages = 0;

        do {
            $params = [
                'part'       => 'contentDetails',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 50,
                'key'        => $this->apiKey,
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::timeout(15)->get($this->baseUrl . 'playlistItems', $params);
            if ($response->failed()) {
                throw new \RuntimeException(
                    'YouTube playlistItems.list HTTP ' . $response->status() . ': ' . $response->body()
                );
            }

            $reachedOlder = false;
            foreach ($response->json('items', []) as $item) {
                $publishedAt = $item['contentDetails']['videoPublishedAt'] ?? null;
                if (!$publishedAt) {
                    continue;
                }

                $day = Carbon::parse($publishedAt)->timezone(self::TIMEZONE)->toDateString();
                if ($day === $today) {
                    $todayIds[] = $item['contentDetails']['videoId'];
                } elseif ($day < $today) {
                    $reachedOlder = true;
                    break;
                }
            }

            $pageToken = $reachedOlder ? null : $response->json('nextPageToken');
        } while ($pageToken && ++$pages < self::MAX_PAGES);

        if (empty($todayIds)) {
            return [];
        }

        $videos = [];
        foreach (array_chunk($todayIds, 50) as $chunk) {
            $response = Http::timeout(15)->get($this->baseUrl . 'videos', [
                'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
                'id'   => implode(',', $chunk),
                'key'  => $this->apiKey,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    'YouTube videos.list HTTP ' . $response->status() . ': ' . $response->body()
                );
            }

            foreach ($response->json('items', []) as $item) {
                $isLiveOrStream = isset($item['liveStreamingDetails'])
                    || (($item['snippet']['liveBroadcastContent'] ?? 'none') !== 'none');
                if ($isLiveOrStream) {
                    continue;
                }

                $videos[] = [
                    'video_id'      => $item['id'],
                    'title'         => $item['snippet']['title'] ?? null,
                    'description'   => $item['snippet']['description'] ?? null,
                    'published_at'  => $item['snippet']['publishedAt'] ?? null,
                    'thumbnail'     => $item['snippet']['thumbnails']['high']['url']
                        ?? $item['snippet']['thumbnails']['default']['url'] ?? null,
                    'duration'      => $item['contentDetails']['duration'] ?? null,
                    'view_count'    => (int) ($item['statistics']['viewCount'] ?? 0),
                    'like_count'    => (int) ($item['statistics']['likeCount'] ?? 0),
                    'comment_count' => (int) ($item['statistics']['commentCount'] ?? 0),
                ];
            }
        }

        return $videos;
    }

    private function buildVideoDoc(array $channel, array $video): array
    {
        return [
            'video_id'      => $video['video_id'],
            'channel_title' => $channel['title'],
            'title'         => $video['title'],
            'description'   => $video['description'],
            'published_at'  => $video['published_at'],
            'duration'      => $video['duration'],
            'permalink'     => 'https://www.youtube.com/watch?v=' . $video['video_id'],
            'thumbnail'     => $video['thumbnail'],
            'metrics'       => [
                'captured_at'   => now()->toIso8601String(),
                'view_count'    => $video['view_count'],
                'like_count'    => $video['like_count'],
                'comment_count' => $video['comment_count'],
            ],
        ];
    }
}
