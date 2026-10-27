<?php

namespace VitisStudio\Tendril\Marketing\Attribution\Models;

use VitisStudio\Tendril\Marketing\Attribution\AttributionModel;

final class FirstTouch implements AttributionModel
{
    public function name(): string
    {
        return 'first_touch';
    }

    public function label(): string
    {
        return 'First touch';
    }

    public function weights(int $count): array
    {
        return $count <= 0 ? [] : [1.0, ...array_fill(0, $count - 1, 0.0)];
    }
}
