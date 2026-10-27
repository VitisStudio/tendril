<?php

namespace VitisStudio\Tendril\Product\Diagnostics;

use Throwable;
use VitisStudio\Tendril\Core\Contracts\DiagnosticCheck;
use VitisStudio\Tendril\Core\Diagnostics\CheckResult;
use VitisStudio\Tendril\Product\Cardinality\CardinalityGuard;

final class PropertyCardinalityCheck implements DiagnosticCheck
{
    public function name(): string
    {
        return 'property_cardinality';
    }

    public function run(): CheckResult
    {
        try {
            $guard = app(CardinalityGuard::class);
            $names = $guard->distinctEventNames();
            $maxNames = (int) config('tendril-product.cardinality.max_event_names', 500);
            $warnings = $guard->propertyWarnings();

            if ($names > $maxNames || $warnings !== []) {
                $details = array_map(fn (array $w) => "{$w['event']}.{$w['property']} ({$w['distinct']}/{$w['sample']})", $warnings);

                return CheckResult::warn(
                    $this->name(),
                    "{$names} event names in 7 days; high-cardinality properties: ".($details === [] ? 'none' : implode(', ', $details)).'.',
                    ['names' => $names, 'properties' => $warnings],
                );
            }

            return CheckResult::ok($this->name(), "{$names} event names in 7 days; no high-cardinality properties.");
        } catch (Throwable $e) {
            return CheckResult::fail($this->name(), $e->getMessage());
        }
    }
}
