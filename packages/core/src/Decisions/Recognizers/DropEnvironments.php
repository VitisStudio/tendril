<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: drop everything recorded in the listed environments.
 */
final class DropEnvironments implements TrackingDecision
{
    /**
     * @param  list<string>  $environments
     */
    public function __construct(private readonly array $environments = ['local', 'testing']) {}

    public function decide(AnalyticsContext $context): Decision
    {
        return in_array($context->environment, $this->environments, true)
            ? Decision::drop('environment')
            : Decision::track();
    }
}
