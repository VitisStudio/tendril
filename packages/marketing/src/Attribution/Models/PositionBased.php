<?php

namespace VitisStudio\Tendril\Marketing\Attribution\Models;

use InvalidArgumentException;
use VitisStudio\Tendril\Marketing\Attribution\AttributionModel;

/**
 * U-shaped: first and last touch get fixed shares (default 40/40), the
 * middle touches share the rest. One touch gets everything; two split evenly
 * in proportion to the first/last shares.
 */
final class PositionBased implements AttributionModel
{
    public function __construct(
        private readonly float $first = 0.4,
        private readonly float $last = 0.4,
    ) {
        if ($first < 0 || $last < 0 || $first + $last > 1.0) {
            throw new InvalidArgumentException('Position weights must be non-negative and sum to at most 1.');
        }
    }

    public function name(): string
    {
        return 'position_based';
    }

    public function label(): string
    {
        return sprintf('Position based (%d/%d)', (int) round($this->first * 100), (int) round($this->last * 100));
    }

    public function weights(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($count === 1) {
            return [1.0];
        }

        if ($count === 2) {
            $total = $this->first + $this->last;

            return $total > 0 ? [$this->first / $total, $this->last / $total] : [0.5, 0.5];
        }

        $middle = (1.0 - $this->first - $this->last) / ($count - 2);

        return [$this->first, ...array_fill(0, $count - 2, $middle), $this->last];
    }
}
