<?php

namespace VitisStudio\Tendril\Product\Diagnostics;

use VitisStudio\Tendril\Core\Contracts\DiagnosticCheck;

final class ProductChecks
{
    /**
     * @return list<DiagnosticCheck>
     */
    public static function all(): array
    {
        return [new AggregationFreshnessCheck, new PropertyCardinalityCheck];
    }
}
