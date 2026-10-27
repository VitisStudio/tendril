<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When disabled nothing is captured, buffered or written. Equivalent to the
    | "null" transport but also skips context and session bookkeeping.
    */
    'enabled' => env('TENDRIL_ENABLED', true),

    // Stamped on every event so staging traffic never pollutes production.
    // Null falls back to the application environment.
    'environment' => env('TENDRIL_ENVIRONMENT'),

    'database' => [
        // All Tendril tables live on this connection (null = default connection).
        'connection' => env('TENDRIL_DB_CONNECTION'),

        // Load the package migrations automatically. Disable if you publish them.
        'run_migrations' => true,

        // Create tendril_events as a natively partitioned table (PostgreSQL
        // declarative partitioning / MySQL RANGE COLUMNS). Must be decided
        // before the first migration runs; see the storage adapters.
        'partitioned' => env('TENDRIL_PARTITIONED', false),

        'insert_chunk' => 500,
    ],

    'transport' => [
        // sync | queue | redis-buffer | null
        'driver' => env('TENDRIL_TRANSPORT', 'queue'),

        // Used when the primary transport throws while handing off a batch.
        // null (drop and report) or "sync" (write directly after the response).
        'fallback' => env('TENDRIL_TRANSPORT_FALLBACK'),

        'queue' => [
            'connection' => env('TENDRIL_QUEUE_CONNECTION'),
            'queue' => env('TENDRIL_QUEUE'),
            'tries' => 5,
            'backoff' => [5, 30, 120, 600],
        ],

        'redis_buffer' => [
            'connection' => env('TENDRIL_REDIS_CONNECTION', 'default'),
            'key' => 'tendril:buffer',
            // Backpressure: batches beyond this many pending are dropped (and counted).
            'max_pending' => 50000,
            // Batches popped per drain iteration.
            'drain_batch' => 100,
            // Batches stuck in the processing list longer than this are requeued.
            'processing_timeout' => 300,
        ],
    ],

    'buffer' => [
        // Flush early (e.g. in long commands) once this many events are buffered.
        'flush_threshold' => 250,
        // Hard cap for in-memory events per unit of work. Excess is dropped and counted.
        'max_events' => 1000,
    ],

    'limits' => [
        'name_max_length' => 150,
        'max_properties' => 64,
        'max_depth' => 3,
        'max_string_length' => 1024,
        'max_payload_bytes' => 16384,
        // Reject events whose occurred_at is further than this into the future.
        'future_skew_seconds' => 300,
        // Events older than this are handled by past_policy: flag | clamp | reject.
        'past_threshold_seconds' => 7 * 86400,
        'past_policy' => 'flag',
        // Cardinality guard on event names, enforced when a batch is flushed.
        'max_distinct_names_per_day' => 1000,
    ],

    'privacy' => [
        // none | truncated | hashed. IP addresses are never stored by default.
        'ip' => 'none',

        // Case-insensitive fragments. Any property key containing one of these
        // is removed at every depth, regardless of allow lists.
        'blocked_keys' => [
            'password', 'passwd', 'secret', 'token', 'authorization', 'cookie',
            'csrf', 'api_key', 'apikey', 'credit_card', 'card_number', 'cvv',
            'cvc', 'ssn', 'iban',
        ],

        // When non-empty, only these top-level property keys are kept.
        'allowed_keys' => [],

        // Property paths removed from events during actor erasure.
        'erasure_strip_paths' => ['email', 'name', 'phone', 'ip'],

        'erasure_chunk' => 1000,
    ],

    'identity' => [
        // cookieless: anonymous identity lives in the Laravel session only.
        // cookie: a persistent first-party cookie, only when consent is granted.
        'mode' => env('TENDRIL_IDENTITY_MODE', 'cookieless'),
        'cookie' => [
            'name' => 'tendril_aid',
            'lifetime_days' => 390,
        ],
        // Close an alias range when the actor logs out so later anonymous
        // browsing on a shared device is not attributed to them.
        'close_alias_on_logout' => true,
    ],

    'sessions' => [
        'inactivity_minutes' => 30,
        'hard_cap_minutes' => 1440,
        // A new arrival with different campaign parameters starts a new session.
        'new_on_campaign_change' => true,
        'session_key' => '_tendril',
    ],

    'acquisition' => [
        // Query parameter captured for each acquisition role.
        'utm' => [
            'source' => 'utm_source',
            'medium' => 'utm_medium',
            'campaign' => 'utm_campaign',
            'content' => 'utm_content',
            'term' => 'utm_term',
        ],
        // Hosts treated as internal referrers in addition to the request host.
        'internal_hosts' => [],
        'click_ids' => [
            // Identifiers, not labels: opt-in and consent-gated.
            'enabled' => false,
            'require_consent' => true,
            'params' => ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid'],
        ],
    ],

    'http' => [
        // Push the capture middleware onto these middleware groups.
        'middleware_groups' => ['web'],
        'track_page_views' => true,
        'page_view_event' => 'page.viewed',
        // Route name patterns never recorded as page views.
        'exclude_routes' => ['tendril.*', 'livewire.*', 'horizon.*', 'telescope.*', 'pulse.*'],
        // Path patterns never recorded as page views.
        'exclude_paths' => ['up', 'health', 'healthz', '_debugbar/*'],
        // Adopt an inbound request id header when it is well formed.
        'request_id_header' => 'X-Request-Id',
        'trust_request_id_header' => false,
        // Adopt an external trace id (W3C traceparent is always understood).
        'trace_id_header' => 'X-Trace-Id',
        'prefetch_headers' => ['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'],
    ],

    'auth' => [
        'enabled' => true,
        // Count "remember me" cookie re-authentication as a login.
        'track_remember_logins' => false,
        // Opt-in security facts. Credentials are never recorded.
        'track_failed' => false,
        'track_lockout' => false,
    ],

    'livewire' => [
        'enabled' => true,
        'event' => 'livewire.action',
        // Methods never recorded (magic/framework methods starting with $ or _ are always excluded).
        'exclude_actions' => [],
        // Component name patterns never recorded.
        'exclude_components' => [],
    ],

    'presence' => [
        'enabled' => true,
        // cache | redis
        'store' => env('TENDRIL_PRESENCE_STORE', 'cache'),
        'cache_store' => env('TENDRIL_PRESENCE_CACHE'),
        'redis_connection' => env('TENDRIL_PRESENCE_REDIS', 'default'),
        'ttl_seconds' => 300,
        'throttle_seconds' => 60,
    ],

    'beacon' => [
        'enabled' => env('TENDRIL_BEACON', false),
        'prefix' => 'tendril',
        'middleware' => ['web'],
        'events' => ['page.viewed', 'outbound.clicked', 'engagement.recorded'],
        'max_payload_bytes' => 16384,
        'max_events' => 20,
        'max_clock_drift_seconds' => 600,
        // Allowed Origin hosts in addition to the application host.
        'allowed_origins' => [],
        'rate_limit_per_minute' => 120,
    ],

    'context' => [
        // Visible keys (request/trace ids) are merged into log context by Laravel.
        'log' => true,
        'max_depth' => 16,
    ],

    'observability' => [
        // Pivot links from an event to your log/trace tooling. Placeholders:
        // {event_id} {request_id} {trace_id} {session_id} {occurred_at}
        'links' => [
            // 'logs' => 'https://logs.example.com/search?q=tendril.request_id:{request_id}',
            // 'trace' => 'https://tracing.example.com/trace/{trace_id}',
        ],
    ],

    'resolvers' => [
        'actor' => VitisStudio\Tendril\Core\Identity\AuthActorResolver::class,
        'tenant' => VitisStudio\Tendril\Core\Identity\NullTenantResolver::class,
        'consent' => VitisStudio\Tendril\Core\Identity\DenyConsentResolver::class,
        // Auth guards consulted by the default actor resolver (null = default guard).
        'guards' => [null],
    ],

    /*
    | TrackingDecision implementations evaluated in order. Entries are class
    | names or [class, constructor-arguments]. Built-in recognizers live in
    | VitisStudio\Tendril\Core\Decisions\Recognizers and are opt-in.
    */
    'decisions' => [],

    'retention' => [
        // Days of raw events to keep. null keeps forever, which requires the
        // explicit opt-in below or tendril:doctor warns.
        'raw_days' => env('TENDRIL_RAW_RETENTION_DAYS', 395),
        'allow_forever' => false,
        'sessions_days' => 395,
        'dead_letters_days' => 30,
        'prune_chunk' => 5000,
        'archive' => [
            'enabled' => false,
            'disk' => env('TENDRIL_ARCHIVE_DISK', 'local'),
            'path' => 'tendril/archive',
        ],
    ],

    'storage' => [
        // auto | portable | pgsql-partitions | mysql-partitions | timescale
        'adapter' => env('TENDRIL_STORAGE_ADAPTER', 'auto'),
        'partitions_ahead_months' => 3,
    ],

    'stats' => [
        'enabled' => true,
        'cache_store' => env('TENDRIL_STATS_CACHE'),
    ],

    'diagnostics' => [
        'max_dirty_buckets' => 5000,
        'max_aggregation_lag_minutes' => 30,
        'max_dropped_ratio' => 0.01,
    ],

    'debug' => [
        // Rethrow capture/ingestion failures instead of failing open. Tests only.
        'throw' => env('TENDRIL_THROW', false),
    ],
];
