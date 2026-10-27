<?php

return [
    // Hypertable chunk interval for tendril_events.
    'chunk_interval' => '7 days',

    // Compress chunks older than this when the edition supports compression
    // (Timescale license; not available on Apache-only builds such as Neon).
    'compress_after' => null,

    // Converting a populated table rewrites it (migrate_data). Refuse above
    // this many rows unless --force is given.
    'max_rows_for_conversion' => 1000000,
];
