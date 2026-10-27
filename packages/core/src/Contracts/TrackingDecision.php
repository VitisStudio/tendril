<?php

namespace VitisStudio\Tendril\Core\Contracts;

use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Host-owned tracking policy. Implementations are evaluated in configured order.
 */
interface TrackingDecision
{
    public function decide(AnalyticsContext $context): Decision;
}
