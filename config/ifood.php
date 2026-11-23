<?php

declare(strict_types=1);

return [

    'base_url' => env('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'),

    'client_id' => env('IFOOD_CLIENT_ID'),

    'client_secret' => env('IFOOD_CLIENT_SECRET'),

    // Polling accepts several merchants; this project runs a single test store.
    'merchant_id' => env('IFOOD_MERCHANT_ID'),

    // Only "centralized" (client_credentials) is implemented. See docs/discovery.md.
    'auth_flow' => env('IFOOD_AUTH_FLOW', 'centralized'),

    'http' => [
        'timeout' => (int) env('IFOOD_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('IFOOD_HTTP_CONNECT_TIMEOUT', 5),
        // Retries inside a single call, only for connection errors and 5xx.
        'retries' => 2,
        'retry_sleep_ms' => 300,
    ],

    'token' => [
        // Refresh the token this many seconds before iFood expires it (it lasts 6h).
        'refresh_margin' => 300,
    ],

    'rate_limit' => [
        // Used when a 429 comes without a usable X-RateLimit-Reset/Retry-After.
        'default_backoff' => 60,
    ],

    // iFood accepts at most 2000 ids per acknowledgment call.
    'ack_chunk_size' => 2000,

];
