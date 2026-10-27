<?php

namespace VitisStudio\Tendril\Core\Contracts;

use Carbon\CarbonImmutable;

/**
 * Database-specific storage maintenance (partitions, hypertables, pruning).
 * PostgreSQL and MySQL behave differently; strategies stay behind this seam
 * instead of pretending the SQL is identical.
 */
interface MaintenanceAdapter
{
    public function name(): string;

    /**
     * @return array<string, bool|int|string|null>
     */
    public function capabilities(): array;

    /**
     * Prepare storage ahead of time (e.g. create partitions up to $until).
     *
     * @return list<string> human-readable actions taken
     */
    public function prepare(CarbonImmutable $until): array;

    /**
     * Delete raw events with occurred_at < $cutoff. Chunked and resumable.
     *
     * @param  (callable(int): void)|null  $progress  receives rows removed so far
     */
    public function pruneBefore(CarbonImmutable $cutoff, int $chunk, ?callable $progress = null): int;

    /**
     * @return array<string, mixed> sizes, partition lists, etc.
     */
    public function describe(): array;
}
