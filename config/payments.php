<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Stars pricing
    |--------------------------------------------------------------------------
    |
    | The admin sets plan prices in USD. We use this value to show the
    | equivalent Telegram Stars cost to the user.
    |
    */
    'telegram_star_usd_value' => (float) env('TELEGRAM_STAR_USD_VALUE', 0.5),

    /*
    |--------------------------------------------------------------------------
    | NOWPayments
    |--------------------------------------------------------------------------
    |
    | Basic NOWPayments configuration for creating crypto invoices.
    | Docs: https://documenter.getpostman.com/view/7907941/2s93JusNJt
    |
    */
    'nowpayments' => [
        'api_key' => env('NOWPAYMENTS_API_KEY'),
        'bearer_token' => env('NOWPAYMENTS_BEARER_TOKEN'),
        'base_url' => env('NOWPAYMENTS_BASE_URL', 'https://api.nowpayments.io/v1'),
        'ipn_callback_url' => env('NOWPAYMENTS_IPN_URL'),
        'success_url' => env('NOWPAYMENTS_SUCCESS_URL'),
        'cancel_url' => env('NOWPAYMENTS_CANCEL_URL'),
        'price_currency' => env('NOWPAYMENTS_PRICE_CURRENCY', 'usd'),
        'pay_currency' => env('NOWPAYMENTS_PAY_CURRENCY', 'usdttrc20'),
        'http_options' => [
            'connect_timeout' => env('NOWPAYMENTS_CONNECT_TIMEOUT', 10),
            'timeout' => env('NOWPAYMENTS_TIMEOUT', 30),
            'force_ip_resolve' => env('NOWPAYMENTS_FORCE_IP_RESOLVE', 'v4'),
            'verify' => env('NOWPAYMENTS_VERIFY_SSL', true),
        ],
    ],
];
