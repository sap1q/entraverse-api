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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mekari' => [
        'client_id' => env('MEKARI_CLIENT_ID', env('JURNAL_CLIENT_ID')),
        'client_secret' => env('MEKARI_CLIENT_SECRET', env('JURNAL_CLIENT_SECRET')),
        'base_url' => env('MEKARI_BASE_URL', env('JURNAL_BASE_URL', 'https://api.mekari.com')),
        'jurnal_base_path' => env('MEKARI_JURNAL_BASE_PATH', '/public/jurnal/api/v1'),
        'webhook_secret' => env('MEKARI_WEBHOOK_SECRET'),
        'timeout' => env('MEKARI_TIMEOUT', 30),
        'connect_timeout' => env('MEKARI_CONNECT_TIMEOUT', 10),
        'debug_signing' => env('MEKARI_DEBUG_SIGNING', false),
    ],

    'rajaongkir' => [
        'key' => env('RAJAONGKIR_API_KEY'),
        'base_url' => env('RAJAONGKIR_BASE_URL', 'https://rajaongkir.komerce.id/api/v1'),
        'timeout' => env('RAJAONGKIR_TIMEOUT', 20),
        'origin_city_id' => env('RAJAONGKIR_ORIGIN_CITY_ID'),
        'origin_district_id' => env('RAJAONGKIR_ORIGIN_DISTRICT_ID'),
        'strict_mode' => env('RAJAONGKIR_STRICT_MODE', true),
        'packaging_weight_grams' => env('RAJAONGKIR_PACKAGING_WEIGHT_GRAMS', 100),
        'cache_ttl_hours' => env('RAJAONGKIR_CACHE_TTL_HOURS', 6),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'snap_base_url' => env('MIDTRANS_SNAP_BASE_URL'),
        'api_base_url' => env('MIDTRANS_API_BASE_URL'),
        'timeout' => env('MIDTRANS_TIMEOUT', 30),
    ],

    'tracking' => [
        'webhook_secret' => env('TRACKING_WEBHOOK_SECRET'),
    ],

    'tiktok_shop' => [
        'app_key' => env('TIKTOK_SHOP_APP_KEY'),
        'app_secret' => env('TIKTOK_SHOP_APP_SECRET'),
        'authorize_url' => env('TIKTOK_SHOP_AUTHORIZE_URL', 'https://services.tiktokshop.com/open/authorize'),
        'token_url' => env('TIKTOK_SHOP_TOKEN_URL', 'https://auth.tiktok-shops.com/api/v2/token/get'),
        'redirect_uri' => env('TIKTOK_SHOP_REDIRECT_URI'),
        'timeout' => env('TIKTOK_SHOP_TIMEOUT', 30),
    ],

    'shopee' => [
        'partner_id' => env('SHOPEE_PARTNER_ID'),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),
        'authorize_url' => env('SHOPEE_AUTHORIZE_URL', 'https://partner.shopeemobile.com/api/v2/shop/auth_partner'),
        'token_url' => env('SHOPEE_TOKEN_URL', 'https://partner.shopeemobile.com/api/v2/auth/token/get'),
        'redirect_uri' => env('SHOPEE_REDIRECT_URI'),
        'timeout' => env('SHOPEE_TIMEOUT', 30),
    ],

];
