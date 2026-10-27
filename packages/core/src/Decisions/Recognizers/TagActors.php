<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use Closure;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: tag (rather than drop) events from actors matched by a callback,
 * e.g. internal staff, so reports can include or exclude them later.
 */
final class TagActors implements TrackingDecision
{
    /**
     * @param  Closure(AnalyticsContext): bool  $matches
     */
    public function __construct(
        private readonly Closure $matches,
        private readonly string $tag = 'internal',
    ) {}

    public function decide(AnalyticsContext $context): Decision
    {
        return ($this->matches)($context) ? Decision::tag([$this->tag]) : Decision::track();
    }
}
