<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: drop events whose route name/pattern matches any of the patterns.
 */
final class DropRoutes implements TrackingDecision
{
    /**
     * @param  list<string>  $routes
     */
    public function __construct(private readonly array $routes = []) {}

    public function decide(AnalyticsContext $context): Decision
    {
        $route = $context->route();

        return $route !== null && $this->routes !== [] && Str::is($this->routes, $route)
            ? Decision::drop('route')
            : Decision::track();
    }
}
