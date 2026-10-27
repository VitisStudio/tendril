<?php

namespace VitisStudio\Tendril\Marketing\Attribution\Models;

use VitisStudio\Tendril\Marketing\Attribution\AttributionModel;

final class Linear implements AttributionModel
{
    public function name(): string
    {
        return 'linear';
    }

    public function label(): string
    {
        return 'Linear';
    }

    public function weights(int $count): array
    {
        return $count <= 0 ? [] : array_fill(0, $count, 1 / $count);
    }
}
