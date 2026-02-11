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

    'binance_pay' => [
        'key'    => env('BINANCE_PAY_KEY'),
        'secret' => env('BINANCE_PAY_SECRET'),
        'env'    => env('BINANCE_PAY_ENV', 'test'), // 'test' para sandbox, 'live' para producción
    ],

    'paypal' => [
        'mode'      => env('PAYPAL_MODE', 'live'), // Por defecto live
        'client_id' => env('PAYPAL_LIVE_CLIENT_ID'), // Tu variable específica
        'secret'    => env('PAYPAL_LIVE_CLIENT_SECRET'), // Tu variable específica
        'app_id'    => env('PAYPAL_LIVE_APP_ID'),
        'currency'  => env('PAYPAL_CURRENCY', 'USD'),
    ],
];
