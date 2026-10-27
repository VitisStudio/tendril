<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: drop events produced by health-check/uptime endpoints.
 */
final class DropHealthChecks implements TrackingDecision
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(private readonly array $paths = ['up', 'health', 'healthz', 'ping', 'status', 'readyz', 'livez']) {}

    public function decide(AnalyticsContext $context): Decision
    {
        $path = $context->path();

        if ($path !== null && Str::is($this->paths, trim($path, '/'))) {
            return Decision::drop('health_check');
        }

        return Decision::track();
    }
}
