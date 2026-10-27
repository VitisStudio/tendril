<?php

return [

    // Hour buckets recomputed per tendril:aggregate run (oldest first).
    'aggregate_limit' => 500,

    // Page-view event name used for route-based feature matching and session metrics.
    'page_view_event' => 'page.viewed',

    'cardinality' => [
        // Recent events sampled per event name when checking property cardinality.
        'sample' => 2000,
        // A property is flagged when it has more distinct values than this...
        'max_distinct_values' => 500,
        // ...and the distinct/sample ratio exceeds this (ids, emails, timestamps).
        'max_distinct_ratio' => 0.5,
        // Distinct event names seen in daily rollups over 7 days before warning.
        'max_event_names' => 500,
    ],

    // Cache seconds for expensive computed reports (funnels, retention, journeys).
    'report_cache_seconds' => 300,

    'lock' => [
        // Cache store holding the tendril:aggregate lock (null = default store).
        // The store must support atomic locks (redis, memcached, file, array,
        // or database with the cache_locks table); others run unlocked.
        'store' => null,
        // Lock lifetime in seconds; 0 disables the lock.
        'seconds' => 900,
    ],
];
