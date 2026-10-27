<?php

namespace VitisStudio\Tendril\Marketing\Attribution;

/**
 * Distributes one conversion's credit over its ordered touchpoints.
 */
interface AttributionModel
{
    public function name(): string;

    public function label(): string;

    /**
     * @return list<float> weights summing to 1 for $count > 0 touchpoints (oldest first)
     */
    public function weights(int $count): array;
}
