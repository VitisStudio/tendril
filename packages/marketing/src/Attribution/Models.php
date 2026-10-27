<?php

namespace VitisStudio\Tendril\Marketing\Attribution;

use InvalidArgumentException;
use VitisStudio\Tendril\Marketing\Attribution\Models\FirstTouch;
use VitisStudio\Tendril\Marketing\Attribution\Models\LastTouch;
use VitisStudio\Tendril\Marketing\Attribution\Models\Linear;
use VitisStudio\Tendril\Marketing\Attribution\Models\PositionBased;

/**
 * Attribution model factory. First/last touch are the defaults; linear and
 * position-based ship as well.
 */
final class Models
{
    public const array NAMES = ['first_touch', 'last_touch', 'linear', 'position_based'];

    public static function make(string $name): AttributionModel
    {
        return match ($name) {
            'first_touch' => new FirstTouch,
            'last_touch' => new LastTouch,
            'linear' => new Linear,
            'position_based' => new PositionBased(
                (float) config('tendril-marketing.attribution.position_weights.first', 0.4),
                (float) config('tendril-marketing.attribution.position_weights.last', 0.4),
            ),
            default => throw new InvalidArgumentException("Unknown attribution model [{$name}]."),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::NAMES as $name) {
            $out[$name] = self::make($name)->label();
        }

        return $out;
    }
}
