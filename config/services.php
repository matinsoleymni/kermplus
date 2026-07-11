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

    'smsbomber' => [
        'url' => env('SMS_BOMBER_URL', 'http://127.0.0.1:8088'),
        'key' => env('SMS_BOMBER_KEY'),
    ],

    'harasser' => [
        'url' => env('HARASSER_URL', env('SMS_BOMBER_URL', 'http://127.0.0.1:8080')),
        'key' => env('HARASSER_KEY', env('SMS_BOMBER_KEY')),
    ],

    'emailbomber' => [
        'url' => env('EMAIL_BOMBER_URL', 'http://127.0.0.1:8080'),
        'key' => env('EMAIL_BOMBER_KEY'),
    ],

    'channel_reaction' => [
        'url' => env('CHANNEL_REACTION_URL', 'http://127.0.0.1:8083'),
        'token' => env('CHANNEL_REACTION_TOKEN'),
    ],

    'boxapi' => [
        'username' => env('BOXAPI_USERNAME'),
        'password' => env('BOXAPI_PASSWORD'),
    ],

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

    'referral' => [
        'reward_threshold' => env('REFERRAL_REWARD_THRESHOLD', 20),
        'reward_plan_id' => env('REFERRAL_REWARD_PLAN_ID'),
    ],

    'kermapp' => [
        'base_url' => env('KERMAPP_BASE_URL'),
        'secret'   => env('KERMAPP_BOT_SECRET'),
    ],

    "go_autofill" => [
        'url' => env("AUTOFORM_URL", "http://127.0.0.1:8084")
    ]

];
