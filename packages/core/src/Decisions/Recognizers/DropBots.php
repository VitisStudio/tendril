<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: drop events from user agents that identify as crawlers/bots.
 * Heuristic only; policy remains the host's decision.
 */
final class DropBots implements TrackingDecision
{
    public const string DEFAULT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|pingdom|uptime|monitor|curl\/|wget\/|python-requests|httpclient|preview/i';

    public function __construct(private readonly string $pattern = self::DEFAULT_PATTERN) {}

    public function decide(AnalyticsContext $context): Decision
    {
        $agent = $context->userAgent();

        if ($context->request !== null && ($agent === null || $agent === '')) {
            return Decision::drop('bot');
        }

        return $agent !== null && preg_match($this->pattern, $agent) === 1
            ? Decision::drop('bot')
            : Decision::track();
    }
}
