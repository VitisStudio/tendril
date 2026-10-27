<?php

namespace VitisStudio\Tendril\Marketing\Attribution\Models;

use VitisStudio\Tendril\Marketing\Attribution\AttributionModel;

final class LastTouch implements AttributionModel
{
    public function name(): string
    {
        return 'last_touch';
    }

    public function label(): string
    {
        return 'Last touch';
    }

    public function weights(int $count): array
    {
        return $count <= 0 ? [] : [...array_fill(0, $count - 1, 0.0), 1.0];
    }
}
