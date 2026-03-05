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

];
