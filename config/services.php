<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Graph API OFICIAL de Meta. Usada por InstagramGraphService (business_discovery)
    // en la ruta /api/scrape/instagram2. Convive con el scraping no oficial de abajo.
    'meta' => [
        'graph_version'  => env('META_GRAPH_VERSION', 'v21.0'),
        'page_token'     => env('META_PAGE_TOKEN'),
        'ig_business_id' => env('META_IG_BUSINESS_ID'),
    ],

    // --- Mismas claves que en cronos-analytics-api, para copiar los servicios 1:1 ---
    'youtube' => [
        'api_key'  => env('YOUTUBE_API_KEY'),
        'base_url' => env('YOUTUBE_API_URL', 'https://www.googleapis.com/youtube/v3/'),
        'channels' => [
            // El Comercio (Perú) — canal id UCLtGUPjKLqa3zgdmhKCZONg
            'elcomercio' => '@diarioelcomercio',
            // RPP Noticias (Perú)
            'rpp'        => '@RPPNoticias',
            // Infobae — canal oficial (sede Argentina)
            'infobae'    => '@infobae',
        ],
    ],

    // Scraping no oficial de X via GraphQL interno. auth_token/csrf_token son
    // cookies de una cuenta logueada (DevTools > Cookies en x.com: auth_token y ct0).
    'x' => [
        'auth_token'   => env('X_AUTH_TOKEN'),
        'csrf_token'   => env('X_CSRF_TOKEN'),
        'bearer_token' => env('X_BEARER_TOKEN', 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA'),
        'base_url'     => env('X_GRAPHQL_URL', 'https://x.com/i/api/graphql/'),
        'max_tweets'   => env('X_MAX_TWEETS', 40),
        'query_ids' => [
            'user_by_screen_name' => env('X_QUERY_ID_USER_BY_SCREEN_NAME'),
            'user_tweets'         => env('X_QUERY_ID_USER_TWEETS'),
        ],
        'accounts' => [
            'elcomercio' => 'elcomercio_peru',
            'rpp'        => 'RPPNoticias',
            'infobae'    => 'infobae',
        ],
    ],

    // Scraping no oficial de Instagram (endpoint interno + Comet). session_id/
    // csrf_token/ds_user_id son cookies de una cuenta logueada en instagram.com.
    'instagram' => [
        'session_id' => env('INSTAGRAM_SESSION_ID'),
        'csrf_token' => env('INSTAGRAM_CSRF_TOKEN'),
        'ds_user_id' => env('INSTAGRAM_DS_USER_ID'),
        'app_id'     => env('INSTAGRAM_APP_ID', '936619743392459'),
        'base_url'   => env('INSTAGRAM_BASE_URL', 'https://www.instagram.com/'),
        'accounts' => [
            'elcomercio' => 'elcomercio',
            'rpp'        => 'rppnoticias',
            'infobae'    => 'infobae',
        ],
    ],

    'storyful' => [
        'session'      => env('STORYFUL_SESSION'),
        'cf_clearance' => env('STORYFUL_CF_CLEARANCE'),
        'base_url'     => env('STORYFUL_BASE_URL', 'https://newswire.storyful.com/'),
        'detail_limit' => env('STORYFUL_DETAIL_LIMIT', 10),
    ],

    // Scraping no oficial de Paginas de Facebook (motor Comet). c_user/xs son
    // cookies de una cuenta logueada. page_id = ID numerico de cada Pagina.
    'facebook' => [
        'c_user'   => env('FACEBOOK_C_USER'),
        'xs'       => env('FACEBOOK_XS'),
        'base_url' => env('FACEBOOK_BASE_URL', 'https://www.facebook.com/'),
        // Binario de Node para el auto-arreglo (sanador). En cron/servidor
        // conviene la ruta absoluta, ej: /home/linuxbrew/.linuxbrew/bin/node
        'node_bin' => env('FACEBOOK_NODE_BIN', 'node'),
        'accounts' => [
            'elcomercio' => [
                'handle'  => 'elcomercio.pe',
                'page_id' => env('FACEBOOK_PAGE_ID_ELCOMERCIO', '100064348614929'),
            ],
            'rpp' => [
                'handle'  => 'rppnoticias',
                'page_id' => env('FACEBOOK_PAGE_ID_RPP', '100070382080379'),
            ],
            'infobae' => [
                'handle'  => 'infobaeperu',
                'page_id' => env('FACEBOOK_PAGE_ID_INFOBAE', '100076010228963'),
            ],
        ],
    ],

];
