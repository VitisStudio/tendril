<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use Symfony\Component\HttpFoundation\IpUtils;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: drop events from requests inside the given CIDR ranges.
 * The IP is inspected in memory only; it is never stored.
 */
final class DropIpRanges implements TrackingDecision
{
    /**
     * @param  list<string>  $ranges
     */
    public function __construct(private readonly array $ranges = []) {}

    public function decide(AnalyticsContext $context): Decision
    {
        $ip = $context->ip();

        return $ip !== null && $this->ranges !== [] && IpUtils::checkIp($ip, $this->ranges)
            ? Decision::drop('ip_range')
            : Decision::track();
    }
}
