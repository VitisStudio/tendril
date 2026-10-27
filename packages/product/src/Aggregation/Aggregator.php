<?php

namespace VitisStudio\Tendril\Product\Aggregation;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Storage\Pruner;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Product\Features\FeatureRepository;

/**
 * Claims dirty hour buckets (oldest first), recomputes every processor's
 * derived data from raw facts, then recomputes the affected days.
 *
 * - Idempotent: each bucket is delete-and-replace inside a transaction.
 * - Late events reopen buckets (writers re-mark them).
 * - Watermarked: a mark is completed only if it was not re-marked meanwhile.
 * - Never recomputes buckets older than the pruned raw horizon.
 */
final class Aggregator
{
    /** Bump when rollup/activity semantics change: triggers a full rebuild. */
    public const int VERSION = 1;

    public function __construct(
        private readonly Database $db,
        private readonly DirtyBuckets $dirty,
        private readonly StateStore $state,
        private readonly ProcessorRegistry $processors,
        private readonly FeatureRepository $features,
        private readonly DefinitionVersions $versions,
    ) {}

    /**
     * @param  (callable(CarbonImmutable, int): void)|null  $progress  called per day with hours processed
     */
    public function run(?int $limit = null, ?callable $progress = null, bool $syncDefinitions = true): AggregationResult
    {
        $lock = $this->lock();

        if ($lock !== null && ! $lock->get()) {
            $result = new AggregationResult;
            $result->locked = true;

            return $result;
        }

        try {
            return $this->execute($limit, $progress, $syncDefinitions);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Overlapping runs would delete and re-insert the same buckets, so one
     * process at a time holds a cache lock. Stores without atomic locks run
     * unlocked; tendril-product.lock.seconds = 0 disables it.
     */
    private function lock(): ?Lock
    {
        $seconds = (int) config('tendril-product.lock.seconds', 900);

        if ($seconds <= 0) {
            return null;
        }

        $store = config('tendril-product.lock.store');
        $repository = app(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null);

        $store = $repository instanceof Repository ? $repository->getStore() : null;

        if (! $store instanceof LockProvider) {
            return null;
        }

        return $store->lock('tendril:aggregate', $seconds);
    }

    /**
     * @param  (callable(CarbonImmutable, int): void)|null  $progress
     */
    private function execute(?int $limit, ?callable $progress, bool $syncDefinitions): AggregationResult
    {
        $started = microtime(true);
        $result = new AggregationResult;

        if ($syncDefinitions) {
            $result->definitionsChanged = $this->versions->sync();
        }

        $limit ??= (int) config('tendril-product.aggregate_limit', 500);
        $prunedBefore = $this->state->instant(Pruner::PRUNED_BEFORE);
        $marks = $this->dirty->claim(max(1, $limit));

        if ($marks === []) {
            $this->finish($result, $started);

            return $result;
        }

        $context = new AggregationContext($this->db, $this->features->all(), AliasResolver::make());
        $processors = $this->processors->all();
        $byDay = [];

        foreach ($marks as $mark) {
            $byDay[Instant::day($mark['bucket_start'])->getTimestamp()][] = $mark;
        }

        ksort($byDay);

        foreach ($byDay as $dayTs => $dayMarks) {
            $day = CarbonImmutable::createFromTimestamp($dayTs, 'UTC');
            $processedHours = 0;

            foreach ($dayMarks as $mark) {
                if ($prunedBefore !== null && $mark['bucket_start']->lessThan($prunedBefore)) {
                    $this->dirty->complete($mark);
                    $result->skipped++;

                    continue;
                }

                $this->db->connection()->transaction(function () use ($processors, $mark, $context): void {
                    foreach ($processors as $processor) {
                        $processor->processHour($mark['bucket_start'], $context);
                    }
                });

                $processedHours++;
            }

            if ($processedHours > 0) {
                $this->db->connection()->transaction(function () use ($processors, $day, $context): void {
                    foreach ($processors as $processor) {
                        $processor->processDay($day, $context);
                    }
                });

                $result->days++;
            }

            foreach ($dayMarks as $mark) {
                $this->dirty->complete($mark);
            }

            $result->hours += $processedHours;

            if ($progress !== null) {
                $progress($day, $processedHours);
            }
        }

        $this->finish($result, $started);

        return $result;
    }

    /**
     * Run until no dirty buckets remain (or $maxRuns is reached).
     */
    public function drain(int $maxRuns = 1000, ?callable $progress = null): AggregationResult
    {
        $total = new AggregationResult;

        for ($i = 0; $i < $maxRuns; $i++) {
            $run = $this->run(progress: $progress, syncDefinitions: $i === 0);
            $total->merge($run);

            if ($run->locked || ($run->hours === 0 && $run->skipped === 0)) {
                break;
            }
        }

        return $total;
    }

    private function finish(AggregationResult $result, float $started): void
    {
        $result->seconds = round(microtime(true) - $started, 3);
        $this->state->putInstant('aggregation.last_run_at', Instant::now());
        $this->state->put('aggregation.last_run', [
            'hours' => $result->hours,
            'days' => $result->days,
            'skipped' => $result->skipped,
            'seconds' => $result->seconds,
        ]);
    }
}
