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
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | When a device is listed under "needs attention" on the dashboard: it has
    | not been heard from for this many minutes, or it reports at least this
    | many unsent events, or its oldest unsent event is older than this many
    | minutes (the shop reports both in its heartbeat).
    |
    */

    'alerts' => [
        'offline_minutes' => (int) env('JOCKO_ALERT_OFFLINE_MINUTES', 60),
        'backlog_events' => (int) env('JOCKO_ALERT_BACKLOG_EVENTS', 500),
        'backlog_age_minutes' => (int) env('JOCKO_ALERT_BACKLOG_AGE_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | `php artisan jocko:prune` (scheduled daily) deletes synchronization events
    | and audit entries older than this many days; 0 keeps them forever. The
    | shop mirror (`shop_records`) is not touched, but once old events are gone
    | `jocko:project-events` can no longer rebuild records they alone described:
    | have the shop run `control:resync` to send a fresh full copy instead.
    |
    */

    'retention' => [
        'events_days' => (int) env('JOCKO_RETENTION_EVENTS_DAYS', 180),
        'audit_days' => (int) env('JOCKO_RETENTION_AUDIT_DAYS', 365),
    ],

    /*
    | Languages an administrator can pick from (code => name shown in the
    | switcher). Each needs a folder in lang/. Persian is right-to-left and
    | gets the Jalali calendar and Persian digits.
    */

    'locales' => [
        'fa' => 'فارسی',
        'en' => 'English',
    ],

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
    | Preview images a shop sends for its pictures (never the originals).
    */

    'files' => [
        'max_kilobytes' => 2048,
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
        // Preview-image uploads per minute per device (one request per image).
        'rate_limit_files_per_device' => (int) env('JOCKO_API_RATE_LIMIT_FILES_PER_DEVICE', 600),

        // Suggested intervals returned from GET /device/me.
        'heartbeat_interval_seconds' => 15,
        'command_poll_interval_seconds' => 15,
    ],

];
