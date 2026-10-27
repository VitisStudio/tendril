<?php

namespace VitisStudio\Tendril\Core\Diagnostics;

use Closure;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\DiagnosticCheck;
use VitisStudio\Tendril\Core\Events\HealthThresholdExceeded;
use VitisStudio\Tendril\Core\Support\FailOpen;

/**
 * Registry of health checks. Core registers its own; product, marketing and
 * timescale add theirs. Every check is isolated: a crashing check reports FAIL.
 */
final class Diagnostics
{
    /** @var array<string, DiagnosticCheck|Closure(): DiagnosticCheck> */
    private array $checks = [];

    /**
     * @param  DiagnosticCheck|class-string<DiagnosticCheck>|Closure(): DiagnosticCheck  $check
     */
    public function register(DiagnosticCheck|string|Closure $check, ?string $name = null): void
    {
        if (is_string($check)) {
            $class = $check;
            $check = fn (): DiagnosticCheck => app($class);
            $name ??= $class;
        }

        $name ??= $check instanceof DiagnosticCheck ? $check->name() : spl_object_hash($check);
        $this->checks[$name] = $check;
    }

    /**
     * @return list<CheckResult>
     */
    public function run(bool $dispatchAlerts = false): array
    {
        $results = [];

        foreach ($this->checks as $key => $check) {
            try {
                $instance = $check instanceof Closure ? $check() : $check;
                $result = $instance->run();
            } catch (Throwable $e) {
                $result = CheckResult::fail(is_string($key) ? $key : 'check', 'Check crashed: '.$e->getMessage());
            }

            $results[] = $result;

            if ($dispatchAlerts && ! $result->isOk()) {
                FailOpen::dispatch(new HealthThresholdExceeded($result));
            }
        }

        return $results;
    }
}
