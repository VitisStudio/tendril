<?php

namespace VitisStudio\Tendril\Core\Events;

use VitisStudio\Tendril\Core\Diagnostics\CheckResult;

/**
 * Dispatched by tendril:doctor (and scheduled health runs) for every check
 * that is not OK, so hosts can route alerts without a hard-wired vendor.
 */
final class HealthThresholdExceeded
{
    public function __construct(
        public readonly CheckResult $result,
    ) {}
}
