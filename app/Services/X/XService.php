<?php

namespace App\Services\X;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XService
{
    private const TIMEZONE = 'America/Lima';
    private const MAX_PAGES = 10;

    private string $authToken;
    private string $csrfToken;
    private string $bearerToken;
    private string $baseUrl;
    private ?string $userByScreenNameQueryId;
    private ?string $userTweetsQueryId;
    private int $maxTweets;

    public function __construct()
    {
        $this->authToken = (string) config('services.x.auth_token');
        $this->csrfToken = (string) config('services.x.csrf_token');
        $this->bearerToken = (string) config('services.x.bearer_token');
        $this->baseUrl = rtrim((string) config('services.x.base_url'), '/') . '/';
        $this->userByScreenNameQueryId = config('services.x.query_ids.user_by_screen_name') ?: null;
        $this->userTweetsQueryId = config('services.x.query_ids.user_tweets') ?: null;
        $this->maxTweets = (int) config('services.x.max_tweets', 40);
    }

    public function sync(): array
    {
        if ($this->authToken === '' || $this->csrfToken === '') {
            throw new \RuntimeException('X_AUTH_TOKEN / X_CSRF_TOKEN no estan configurados');
        }
        if (!$this->userByScreenNameQueryId || !$this->userTweetsQueryId) {
            throw new \RuntimeException('Los query_id de X (GraphQL) no estan configurados');
        }

        $date = now(self::TIMEZONE)->toDateString();
        $accountsData = [];

        foreach ((array) config('services.x.accounts', []) as $key => $handle) {
            try {
                $user = $this->resolveUser($handle);
                if ($user === null) {
                    Log::warning("X: no se pudo resolver la cuenta '{$handle}' (key: {$key})");
                    continue;
                }

                $byId = [];
                foreach ($this->fetchTodaysTweets($user) as $tweet) {
                    $byId[$tweet['tweet_id']] = $this->buildTweetDoc($user, $tweet);
                }

                $accountsData[$key] = array_values($byId);
            } catch (\Throwable $exception) {
                Log::error("X sync error para '{$key}': " . $exception->getMessage());
            }
        }

        return [
            'date'     => $date,
            'accounts' => $accountsData,
        ];
    }

    private function headers(): array
    {
        return [
            'Authorization'             => 'Bearer ' . $this->bearerToken,
            'Cookie'                    => "auth_token={$this->authToken}; ct0={$this->csrfToken}",
            'x-csrf-token'              => $this->csrfToken,
            'x-twitter-active-user'     => 'yes',
            'x-twitter-auth-type'       => 'OAuth2Session',
            'x-twitter-client-language' => 'es',
        ];
    }

    private function resolveUser(string $handle): ?array
    {
        $variables = [
            'screen_name'           => ltrim($handle, '@'),
            'withGrokTranslatedBio' => true,
        ];

        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get($this->baseUrl . $this->userByScreenNameQueryId . '/UserByScreenName', [
                'variables'    => json_encode($variables),
                'features'     => json_encode($this->userByScreenNameFeatures()),
                'fieldToggles' => json_encode(['withPayments' => false, 'withAuxiliaryUserLabels' => true]),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'X UserByScreenName HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $result = $response->json('data.user.result');
        if (!$result) {
            return null;
        }

        return [
            'user_id'  => $result['rest_id'] ?? null,
            'name'     => $result['legacy']['name'] ?? ($result['core']['name'] ?? null),
            // X movio screen_name de "legacy" a "core" en algunas respuestas; si
            // no viene por ninguno, caemos al handle configurado para que el
            // permalink (https://x.com/{username}/status/{id}) no quede sin usuario.
            'username' => $result['legacy']['screen_name']
                ?? ($result['core']['screen_name'] ?? ltrim($handle, '@')),
        ];
    }

    private function fetchTodaysTweets(array $user): array
    {
        $userId = $user['user_id'] ?? null;
        if (!$userId) {
            return [];
        }

        $today = now(self::TIMEZONE)->toDateString();
        $tweets = [];
        $cursor = null;
        $pages = 0;

        do {
            $variables = [
                'userId'                                => $userId,
                'count'                                  => $this->maxTweets,
                'includePromotedContent'                 => true,
                'withQuickPromoteEligibilityTweetFields' => true,
                'withVoice'                               => true,
            ];
            if ($cursor) {
                $variables['cursor'] = $cursor;
            }

            $response = Http::withHeaders($this->headers())
                ->timeout(15)
                ->get($this->baseUrl . $this->userTweetsQueryId . '/UserTweets', [
                    'variables'    => json_encode($variables),
                    'features'     => json_encode($this->userTweetsFeatures()),
                    'fieldToggles' => json_encode(['withArticlePlainText' => false]),
                ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    'X UserTweets HTTP ' . $response->status() . ': ' . $response->body()
                );
            }

            [$pageTweets, $nextCursor, $reachedOlder] = $this->parseTimeline($response->json() ?? [], $today);
            $tweets = array_merge($tweets, $pageTweets);

            $cursor = $reachedOlder ? null : $nextCursor;
        } while ($cursor && ++$pages < self::MAX_PAGES);

        return $tweets;
    }

    private function parseTimeline(array $payload, string $today): array
    {
        $instructions = $payload['data']['user']['result']['timeline_v2']['timeline']['instructions']
            ?? $payload['data']['user']['result']['timeline']['timeline']['instructions']
            ?? [];

        $tweets = [];
        $cursor = null;
        $reachedOlder = false;

        foreach ($instructions as $instruction) {
            foreach ($instruction['entries'] ?? [] as $entry) {
                $entryId = $entry['entryId'] ?? '';

                if (str_starts_with($entryId, 'cursor-bottom-')) {
                    $cursor = $entry['content']['value'] ?? null;
                    continue;
                }

                if (!str_starts_with($entryId, 'tweet-')) {
                    continue;
                }

                $result = $entry['content']['itemContent']['tweet_results']['result'] ?? null;
                $legacy = $result['legacy'] ?? $result['tweet']['legacy'] ?? null;
                if (!$legacy) {
                    continue;
                }

                $createdAt = $legacy['created_at'] ?? null;
                if (!$createdAt) {
                    continue;
                }

                $day = Carbon::parse($createdAt)->timezone(self::TIMEZONE)->toDateString();
                if ($day < $today) {
                    $reachedOlder = true;
                    continue;
                }
                if ($day !== $today) {
                    continue;
                }

                $tweets[] = [
                    'tweet_id'      => $legacy['id_str'] ?? ($result['rest_id'] ?? null),
                    'text'          => $legacy['full_text'] ?? null,
                    'created_at'    => $createdAt,
                    'retweet_count' => (int) ($legacy['retweet_count'] ?? 0),
                    'like_count'    => (int) ($legacy['favorite_count'] ?? 0),
                    'reply_count'   => (int) ($legacy['reply_count'] ?? 0),
                    'quote_count'   => (int) ($legacy['quote_count'] ?? 0),
                    'view_count'    => (int) ($result['views']['count'] ?? 0),
                ];
            }
        }

        return [$tweets, $cursor, $reachedOlder];
    }

    private function buildTweetDoc(array $user, array $tweet): array
    {
        return [
            'tweet_id'   => $tweet['tweet_id'],
            'username'   => $user['username'],
            'text'       => $tweet['text'],
            'created_at' => $tweet['created_at'],
            'permalink'  => 'https://x.com/' . $user['username'] . '/status/' . $tweet['tweet_id'],
            'metrics'    => [
                'captured_at'   => now()->toIso8601String(),
                'retweet_count' => $tweet['retweet_count'],
                'like_count'    => $tweet['like_count'],
                'reply_count'   => $tweet['reply_count'],
                'quote_count'   => $tweet['quote_count'],
                'view_count'    => $tweet['view_count'],
            ],
        ];
    }

    private function userByScreenNameFeatures(): array
    {
        return [
            'hidden_profile_subscriptions_enabled'                             => true,
            'profile_label_improvements_pcf_label_in_post_enabled'             => true,
            'responsive_web_profile_redirect_enabled'                          => false,
            'rweb_tipjar_consumption_enabled'                                  => false,
            'verified_phone_label_enabled'                                     => false,
            'subscriptions_verification_info_is_identity_verified_enabled'     => true,
            'subscriptions_verification_info_verified_since_enabled'           => true,
            'highlights_tweets_tab_ui_enabled'                                 => true,
            'responsive_web_twitter_article_notes_tab_enabled'                 => true,
            'subscriptions_feature_can_gift_premium'                          => true,
            'creator_subscriptions_tweet_preview_api_enabled'                  => true,
            'responsive_web_graphql_skip_user_profile_image_extensions_enabled' => false,
            'responsive_web_graphql_timeline_navigation_enabled'               => true,
        ];
    }

    private function userTweetsFeatures(): array
    {
        return [
            'rweb_video_screen_enabled'                                                    => false,
            'rweb_cashtags_enabled'                                                         => true,
            'profile_label_improvements_pcf_label_in_post_enabled'                          => true,
            'responsive_web_profile_redirect_enabled'                                       => false,
            'rweb_tipjar_consumption_enabled'                                               => false,
            'verified_phone_label_enabled'                                                  => false,
            'creator_subscriptions_tweet_preview_api_enabled'                               => true,
            'responsive_web_graphql_timeline_navigation_enabled'                            => true,
            'responsive_web_graphql_skip_user_profile_image_extensions_enabled'             => false,
            'premium_content_api_read_enabled'                                              => false,
            'communities_web_enable_tweet_community_results_fetch'                          => true,
            'c9s_tweet_anatomy_moderator_badge_enabled'                                      => true,
            'responsive_web_grok_analyze_button_fetch_trends_enabled'                       => false,
            'responsive_web_grok_analyze_post_followups_enabled'                            => true,
            'rweb_cashtags_composer_attachment_enabled'                                     => true,
            'responsive_web_jetfuel_frame'                                                  => true,
            'responsive_web_grok_share_attachment_enabled'                                  => true,
            'responsive_web_grok_annotations_enabled'                                       => true,
            'articles_preview_enabled'                                                       => true,
            'responsive_web_edit_tweet_api_enabled'                                          => true,
            'rweb_conversational_replies_downvote_enabled'                                  => false,
            'graphql_is_translatable_rweb_tweet_is_translatable_enabled'                    => true,
            'view_counts_everywhere_api_enabled'                                            => true,
            'longform_notetweets_consumption_enabled'                                       => true,
            'responsive_web_twitter_article_tweet_consumption_enabled'                       => true,
            'content_disclosure_indicator_enabled'                                          => true,
            'content_disclosure_ai_generated_indicator_enabled'                             => true,
            'responsive_web_grok_show_grok_translated_post'                                 => true,
            'responsive_web_grok_analysis_button_from_backend'                              => true,
            'post_ctas_fetch_enabled'                                                        => false,
            'freedom_of_speech_not_reach_fetch_enabled'                                     => true,
            'standardized_nudges_misinfo'                                                    => true,
            'tweet_with_visibility_results_prefer_gql_limited_actions_policy_enabled'        => true,
            'longform_notetweets_rich_text_read_enabled'                                     => true,
            'longform_notetweets_inline_media_enabled'                                       => false,
            'responsive_web_grok_image_annotation_enabled'                                   => true,
            'responsive_web_grok_imagine_annotation_enabled'                                 => true,
            'responsive_web_grok_community_note_auto_translation_is_enabled'                 => true,
            'responsive_web_enhance_cards_enabled'                                           => false,
        ];
    }
}
