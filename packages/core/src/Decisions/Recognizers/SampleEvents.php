<?php

namespace VitisStudio\Tendril\Core\Decisions\Recognizers;

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;

/**
 * Opt-in: sample high-volume event names, e.g. ['livewire.*' => 0.25].
 */
final class SampleEvents implements TrackingDecision
{
    /**
     * @param  array<string, float|int>  $rates
     */
    public function __construct(private readonly array $rates = []) {}

    public function decide(AnalyticsContext $context): Decision
    {
        foreach ($this->rates as $pattern => $rate) {
            if (Str::is($pattern, $context->name)) {
                return Decision::sample((float) $rate);
            }
        }

        return Decision::track();
    }
}
