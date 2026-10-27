# Configuration

Every key below exists in the package's published config file. Defaults are the values shipped
in the file; `env()` calls are noted where present.

## `config/tendril.php` (tendril-core)

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `env('TENDRIL_ENABLED', true)` | Master switch. Disabled also skips context/session bookkeeping, not just storage. |
| `environment` | `env('TENDRIL_ENVIRONMENT')` | Stamped on every event; falls back to `app()->environment()`. |
| `database.connection` | `env('TENDRIL_DB_CONNECTION')` | Connection every Tendril table lives on; `null` = default connection. |
| `database.run_migrations` | `true` | Auto-load package migrations; disable if you publish and version them yourself. |
| `database.partitioned` | `env('TENDRIL_PARTITIONED', false)` | Create `tendril_events` natively partitioned (PostgreSQL declarative partitioning / MySQL RANGE COLUMNS). Must be decided before the first migration. |
| `database.insert_chunk` | `500` | Row-chunk size for batch inserts. |
| `transport.driver` | `env('TENDRIL_TRANSPORT', 'queue')` | `sync` \| `queue` \| `redis-buffer` \| `null`. |
| `transport.fallback` | `env('TENDRIL_TRANSPORT_FALLBACK')` | `null` (drop + report) or `sync`, used when the primary transport throws. |
| `transport.queue.connection` / `.queue` | `env('TENDRIL_QUEUE_CONNECTION')` / `env('TENDRIL_QUEUE')` | Queue connection/name for the `queue` transport. |
| `transport.queue.tries` | `5` | Job retry count. |
| `transport.queue.backoff` | `[5, 30, 120, 600]` | Retry backoff schedule (seconds). |
| `transport.redis_buffer.connection` | `env('TENDRIL_REDIS_CONNECTION', 'default')` | Redis connection for the `redis-buffer` transport. |
| `transport.redis_buffer.key` | `tendril:buffer` | Redis key prefix for the buffer's pending/processing lists. |
| `transport.redis_buffer.max_pending` | `50000` | Backpressure: batches beyond this are dropped and counted. |
| `transport.redis_buffer.drain_batch` | `100` | Batches popped per `tendril:drain` iteration. |
| `transport.redis_buffer.processing_timeout` | `300` | Seconds before a claimed-but-unacknowledged batch is requeued. |
| `buffer.flush_threshold` | `250` | Flush early once this many events are buffered in one unit of work. |
| `buffer.max_events` | `1000` | Hard cap per unit of work; excess events are dropped and counted. |
| `limits.name_max_length` | `150` | Max event name length. |
| `limits.max_properties` | `64` | Max property keys. |
| `limits.max_depth` | `3` | Max nesting depth of properties/context. |
| `limits.max_string_length` | `1024` | Max string value length. |
| `limits.max_payload_bytes` | `16384` | Max combined JSON size of properties + context. |
| `limits.future_skew_seconds` | `300` | Events timestamped further ahead than this are rejected. |
| `limits.past_threshold_seconds` | `604800` (7 days) | Age past which `past_policy` applies. |
| `limits.past_policy` | `flag` | `flag` \| `clamp` \| `reject` for events older than the threshold. |
| `limits.max_distinct_names_per_day` | `1000` | Cardinality guard on event names. |
| `privacy.ip` | `none` | `none` \| `truncated` \| `hashed`. IPs are never stored by default. |
| `privacy.blocked_keys` | password/secret/token/… fragments | Property keys removed at every depth regardless of allow lists. |
| `privacy.allowed_keys` | `[]` | When non-empty, only these top-level property keys are kept. |
| `privacy.erasure_strip_paths` | `['email', 'name', 'phone', 'ip']` | Property paths removed during actor erasure. |
| `privacy.erasure_chunk` | `1000` | Rows rewritten per erasure transaction. |
| `identity.mode` | `env('TENDRIL_IDENTITY_MODE', 'cookieless')` | `cookieless` (session-bound) or `cookie` (persistent, consent-gated). Privacy default is cookieless. |
| `identity.cookie.name` | `tendril_aid` | Persistent anonymous-id cookie name. |
| `identity.cookie.lifetime_days` | `390` | Cookie lifetime. |
| `identity.close_alias_on_logout` | `true` | Close the alias range on logout so later anonymous use of a shared device is not attributed to the previous user. |
| `sessions.inactivity_minutes` | `30` | Session ends after this much inactivity. |
| `sessions.hard_cap_minutes` | `1440` | Absolute session length cap. |
| `sessions.new_on_campaign_change` | `true` | A new arrival with different campaign parameters starts a new session (see ADR 0005). |
| `sessions.session_key` | `_tendril` | Laravel session key Tendril's session state is stored under. |
| `acquisition.utm` | `['source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign', 'content' => 'utm_content', 'term' => 'utm_term']` | Role => query-parameter map, honoured by `AcquisitionParser::parameterNames()`. A plain list (5 values, no keys) is read in `source, medium, campaign, content, term` order instead. Renaming a role's parameter only affects server-side capture (see the beacon note below). |
| `acquisition.internal_hosts` | `[]` | Extra hosts treated as internal referrers besides the current request host. |
| `acquisition.click_ids.enabled` | `false` | Click-ID capture is opt-in. |
| `acquisition.click_ids.require_consent` | `true` | Click IDs additionally require consent when enabled. |
| `acquisition.click_ids.params` | gclid, gbraid, wbraid, fbclid, msclkid, ttclid, li_fat_id, twclid | Click-ID query parameters recognized. |
| `http.middleware_groups` | `['web']` | Middleware groups the capture middleware is pushed onto. |
| `http.track_page_views` | `true` | Toggle automatic page-view recording. |
| `http.page_view_event` | `page.viewed` | Event name used for page views. |
| `http.exclude_routes` | `tendril.*`, `livewire.*`, `horizon.*`, `telescope.*`, `pulse.*` | Route-name patterns never recorded as page views. |
| `http.exclude_paths` | `up`, `health`, `healthz`, `_debugbar/*` | Path patterns never recorded as page views. |
| `http.request_id_header` | `X-Request-Id` | Header checked when `trust_request_id_header` is on. |
| `http.trust_request_id_header` | `false` | Whether an inbound request-id header is adopted. |
| `http.trace_id_header` | `X-Trace-Id` | Header used to adopt an external trace id (W3C `traceparent` is always understood). |
| `http.prefetch_headers` | `Purpose`, `Sec-Purpose`, `X-Purpose`, `X-Moz` | Headers used to recognize browser prefetch requests. |
| `auth.enabled` | `true` | Toggle auth-event capture. |
| `auth.track_remember_logins` | `false` | Count "remember me" cookie re-authentication as a login. |
| `auth.track_failed` | `false` | Opt-in: record failed login attempts (never credentials). |
| `auth.track_lockout` | `false` | Opt-in: record lockouts. |
| `livewire.enabled` | `true` | Toggle the Livewire `ComponentHook`. |
| `livewire.event` | `livewire.action` | Event name for Livewire actions. |
| `livewire.exclude_actions` | `[]` | Method-name patterns never recorded. |
| `livewire.exclude_components` | `[]` | Component-name patterns never recorded. |
| `presence.enabled` | `true` | Toggle presence tracking. |
| `presence.store` | `env('TENDRIL_PRESENCE_STORE', 'cache')` | `cache` or `redis`. |
| `presence.cache_store` | `env('TENDRIL_PRESENCE_CACHE')` | Cache store name when using the cache presence store. |
| `presence.redis_connection` | `env('TENDRIL_PRESENCE_REDIS', 'default')` | Redis connection for the Redis presence store. |
| `presence.ttl_seconds` | `300` | How long a touch counts as "present". |
| `presence.throttle_seconds` | `60` | Minimum gap between presence refreshes per actor. |
| `beacon.enabled` | `env('TENDRIL_BEACON', false)` | Off by default. |
| `beacon.prefix` | `tendril` | Route prefix for `/collect` and `/beacon.js`. |
| `beacon.middleware` | `['web']` | Middleware applied to the beacon routes. |
| `beacon.events` | `page.viewed`, `outbound.clicked`, `engagement.recorded` | Event names the beacon is allowed to record. |
| `beacon.max_payload_bytes` | `16384` | Max request body size. |
| `beacon.max_events` | `20` | Max events per beacon request. |
| `beacon.max_clock_drift_seconds` | `600` | Client-supplied timestamps are trusted only within this drift. |
| `beacon.allowed_origins` | `[]` | Extra allowed `Origin`/`Referer` hosts besides the app's own host. |
| `beacon.rate_limit_per_minute` | `120` | Per-session (or per-IP) rate limit on `/tendril/collect`. |

The beacon script (`packages/core/resources/js/tendril-beacon.js`) always collects the standard
`utm_*` query parameter names from the page URL; it does not read `tendril.acquisition.utm`.
Custom parameter names configured there apply to server-side (middleware) capture only.
| `context.log` | `true` | Merge visible context keys (request/trace ids) into Laravel's log context. |
| `context.max_depth` | `16` | Max depth of the Context push-trace stack. |
| `observability.links` | `[]` (commented examples) | URL templates for pivoting from an event to external log/trace tooling. |
| `resolvers.actor` | `AuthActorResolver::class` | `ActorResolver` implementation. |
| `resolvers.tenant` | `NullTenantResolver::class` | `TenantResolver` implementation. |
| `resolvers.consent` | `DenyConsentResolver::class` | `ConsentResolver` implementation (default denies consent). |
| `resolvers.guards` | `[null]` | Auth guards the default actor resolver checks (`null` = default guard). |
| `decisions` | `[]` | `TrackingDecision` class names (or `[class, args]`) evaluated in order. |
| `retention.raw_days` | `env('TENDRIL_RAW_RETENTION_DAYS', 395)` | Raw-event retention in days; `null` = forever. |
| `retention.allow_forever` | `false` | Required to silence `tendril:doctor` when `raw_days` is `null`. |
| `retention.sessions_days` | `395` | Session/touchpoint retention. |
| `retention.dead_letters_days` | `30` | Dead-letter retention. |
| `retention.prune_chunk` | `5000` | Rows per prune transaction. |
| `retention.archive.enabled` | `false` | Archive raw events to NDJSON before pruning. |
| `retention.archive.disk` | `env('TENDRIL_ARCHIVE_DISK', 'local')` | Filesystem disk for archives. |
| `retention.archive.path` | `tendril/archive` | Archive path prefix on that disk. |
| `storage.adapter` | `env('TENDRIL_STORAGE_ADAPTER', 'auto')` | `auto` \| `portable` \| `pgsql-partitions` \| `mysql-partitions` \| `timescale`. |
| `storage.partitions_ahead_months` | `3` | Months of partitions/chunks `tendril:partitions` prepares ahead. |
| `stats.enabled` | `true` | Toggle ingestion stats collection. |
| `stats.cache_store` | `env('TENDRIL_STATS_CACHE')` | Cache store for ingestion stats. |
| `diagnostics.max_dirty_buckets` | `5000` | `tendril:doctor` warning threshold for the aggregation backlog. |
| `diagnostics.max_aggregation_lag_minutes` | `30` | Warning threshold for the oldest unprocessed bucket. |
| `diagnostics.max_dropped_ratio` | `0.01` | Warning threshold for dropped+failed / accepted events. |
| `debug.throw` | `env('TENDRIL_THROW', false)` | Rethrow capture/ingestion failures instead of failing open. Tests only; `tendril:doctor` fails if on in production. |

### Privacy defaults

Out of the box: `privacy.ip = none` (no IP storage), `identity.mode = cookieless` (no persistent
cookie), `acquisition.click_ids.enabled = false` (no click-ID storage), and
`resolvers.consent = DenyConsentResolver` (consent denied until the host wires a real
`ConsentResolver`). Opting into a more identifying posture means: setting `identity.mode =
cookie` (still gated by `ConsentResolver::hasAnalyticsConsent()`), setting
`acquisition.click_ids.enabled = true` (plus `require_consent`, on by default), and setting
`privacy.ip` to `truncated` or `hashed`. See `packages/core/src/Privacy/IpAnonymizer.php` for
the truncation/hash behavior.

## `config/tendril-product.php` (tendril-product)

| Key | Default | Purpose |
|---|---|---|
| `aggregate_limit` | `500` | Hour buckets processed per `tendril:aggregate` run (oldest first). |
| `page_view_event` | `page.viewed` | Event name used for route-based feature matching and session metrics. |
| `cardinality.sample` | `2000` | Recent events sampled per event name for the cardinality check. |
| `cardinality.max_distinct_values` | `500` | Distinct-value threshold that flags a property. |
| `cardinality.max_distinct_ratio` | `0.5` | Distinct/sample ratio threshold (catches ids, emails, timestamps). |
| `cardinality.max_event_names` | `500` | Distinct event names over 7 days of daily rollups before warning. |
| `report_cache_seconds` | `300` | Cache TTL for expensive computed reports (funnels, retention, journeys). |
| `lock.store` | `null` | Cache store holding the `tendril:aggregate` lock; `null` = default cache store. Must support atomic locks (`Illuminate\Contracts\Cache\LockProvider` — redis, memcached, file, array, or `database` with the `cache_locks` table); other stores run `tendril:aggregate` unlocked. |
| `lock.seconds` | `900` | Lock lifetime in seconds; `0` disables the lock entirely. |

## `config/tendril-marketing.php` (tendril-marketing)

| Key | Default | Purpose |
|---|---|---|
| `attribution.default_model` | `last_touch` | `first_touch` \| `last_touch` \| `linear` \| `position_based`. |
| `attribution.lookback_days` | `90` | Touchpoint lookback window for attribution. |
| `attribution.position_weights` | `['first' => 0.4, 'last' => 0.4]` | First/last weights for `position_based`; the remainder splits the middle touchpoints. |
| `default_currency` | `env('TENDRIL_DEFAULT_CURRENCY', 'USD')` | Currency used when a conversion defines neither a fixed nor a property currency. |
| `currency_exponents` | ISO 4217 exceptions (JPY, KWD, …) | Minor-unit exponents that differ from the default of 2. |

## `config/tendril-timescale.php` (tendril-timescale)

| Key | Default | Purpose |
|---|---|---|
| `chunk_interval` | `7 days` | Hypertable chunk interval for `tendril_events`. |
| `compress_after` | `null` | Compress chunks older than this when the Timescale license supports it (not available on Apache-only builds, e.g. Neon). |
| `max_rows_for_conversion` | `1000000` | Row-count guard: converting a populated table rewrites it; above this, `--force` is required. |

## `config/tendril-destinations.php` (tendril-destinations)

| Key | Default | Purpose |
|---|---|---|
| `destinations` | `[]` | Per-destination config (`driver`, credentials from `env()`, `conversions` key mapping). Commented examples for `webhook`, `meta` and `google_ads`. |
| `batch_size` | `100` | Conversions sent per outbound batch. |
| `settle_minutes` | `5` | Only conversions older than this are sent, so late events settle first. |
| `lookback_hours` | `72` | Window scanned per dispatch run. |
| `max_attempts` | `5` | Delivery attempts before a conversion is left undelivered. |
| `timeout_seconds` | `10` | HTTP timeout per destination request. |

Destination credentials always come from environment variables (`env()` in the commented
examples); nothing in `tendril-destinations.destinations` reads from the database.
