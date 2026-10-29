<?php

use App\Services\ShopRecordProjector;

return [

    /*
    |--------------------------------------------------------------------------
    | Transport security
    |--------------------------------------------------------------------------
    |
    | When enabled, plain HTTP requests are refused (API) or redirected (admin).
    | Defaults to on in production. Behind a TLS-terminating proxy, also set
    | TRUSTED_PROXIES so the original scheme is detected.
    |
    */

    'require_https' => (bool) env('JOCKO_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Device connectivity
    |--------------------------------------------------------------------------
    |
    | A device counts as online while its last_seen_at (always stamped with the
    | central server's clock, never the device's) is newer than this threshold.
    |
    */

    'online_threshold_minutes' => (int) env('JOCKO_ONLINE_THRESHOLD_MINUTES', 15),

    /*
    | A device whose last synchronization failure is newer than this window is
    | listed as having a recent synchronization failure.
    */

    'sync_failure_window_hours' => (int) env('JOCKO_SYNC_FAILURE_WINDOW_HOURS', 24),

    /*
    | Timezone used when showing timestamps to administrators. Storage is UTC.
    */

    'display_timezone' => env('JOCKO_DISPLAY_TIMEZONE', 'Asia/Tehran'),

    /*
    |--------------------------------------------------------------------------
    | Synchronization ingestion limits
    |--------------------------------------------------------------------------
    */

    'sync' => [
        'max_events_per_batch' => 100,
        'max_payload_bytes' => 65536,
        'max_payload_depth' => 16,
        'max_request_bytes' => 8 * 1024 * 1024,

        /*
        | Classes implementing App\Contracts\SyncEventHandler. They run after an
        | event has been durably stored, outside the ingestion transaction. The
        | shop mirror is the only consumer: it is read-only and feeds the
        | shop data pages. `php artisan jocko:project-events` replays the inbox.
        */
        'handlers' => [
            ShopRecordProjector::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote commands
    |--------------------------------------------------------------------------
    */

    'commands' => [
        'default_ttl_minutes' => 24 * 60,
        'min_ttl_minutes' => 1,
        'max_ttl_minutes' => 7 * 24 * 60,
        'max_deliver_per_poll' => 50,
        'default_deliver_per_poll' => 20,
        'max_result_bytes' => 32768,
    ],

    /*
    |--------------------------------------------------------------------------
    | Device API
    |--------------------------------------------------------------------------
    */

    'api' => [
        // Requests per minute per source IP (applies before authentication).
        'rate_limit_per_ip' => (int) env('JOCKO_API_RATE_LIMIT_PER_IP', 600),
        // Requests per minute per authenticated device.
        'rate_limit_per_device' => (int) env('JOCKO_API_RATE_LIMIT_PER_DEVICE', 120),

        // Suggested intervals returned from GET /device/me.
        'heartbeat_interval_seconds' => 60,
        'command_poll_interval_seconds' => 60,
    ],

];
