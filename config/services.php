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

    // Sign in with Apple: the token audience is the iOS bundle ID (plus a Services ID if web sign-in is added).
    'apple' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_CLIENT_IDS', 'hideya.qla.dev'))))),
    ],

    // Google Sign-In: the OAuth client IDs (web, iOS, Android) whose ID tokens are accepted.
    'google' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
    ],

    // RevenueCat webhook (Authorization header value set in the RevenueCat dashboard).
    'revenuecat' => [
        'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
    ],

    // Expo push service; an access token is only needed if "enhanced push security" is turned on.
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

];
