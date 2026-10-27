<?php

namespace VitisStudio\Tendril\Product\Diagnostics;

use Throwable;
use VitisStudio\Tendril\Core\Contracts\DiagnosticCheck;
use VitisStudio\Tendril\Core\Diagnostics\CheckResult;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;

final class AggregationFreshnessCheck implements DiagnosticCheck
{
    public function name(): string
    {
        return 'aggregation_freshness';
    }

    public function run(): CheckResult
    {
        try {
            $state = app(StateStore::class);
            $last = $state->instant('aggregation.last_run_at');
            $version = (int) $state->get('aggregation.version', 0);
            $maxLag = (int) config('tendril.diagnostics.max_aggregation_lag_minutes', 30);

            if ($version !== 0 && $version !== Aggregator::VERSION) {
                return CheckResult::warn($this->name(), 'Rollup version changed; run tendril:aggregate --loop to rebuild derived data.');
            }

            if ($last === null) {
                return CheckResult::warn($this->name(), 'tendril:aggregate has never run; schedule it every minute.');
            }

            $minutes = (int) $last->diffInMinutes(Instant::now(), true);
            $data = ['minutes' => $minutes, 'last_run' => $state->get('aggregation.last_run')];

            return $minutes > $maxLag
                ? CheckResult::warn($this->name(), "Last aggregation {$minutes} minutes ago.", $data)
                : CheckResult::ok($this->name(), "Last aggregation {$minutes} minutes ago.", $data);
        } catch (Throwable $e) {
            return CheckResult::fail($this->name(), $e->getMessage());
        }
    }
}
