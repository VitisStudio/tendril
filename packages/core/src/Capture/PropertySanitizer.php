<?php

namespace VitisStudio\Tendril\Core\Capture;

use BackedEnum;
use DateTimeInterface;
use Stringable;
use UnitEnum;

/**
 * Bounds and scrubs property/context payloads.
 *
 * - removes keys matching the privacy block list at every depth
 * - drops objects (no model snapshots), keeps scalars, dates and enums
 * - drops null values (absent and null are the same thing in Tendril)
 * - truncates long strings, caps depth and the number of leaf values
 */
final class PropertySanitizer
{
    /**
     * @param  list<string>  $blocked  lower-case key fragments
     * @param  list<string>  $allowed  top-level allow list (empty = all)
     */
    public function __construct(
        private readonly array $blocked,
        private readonly array $allowed,
        private readonly int $maxDepth,
        private readonly int $maxLeaves,
        private readonly int $maxStringLength,
    ) {}

    public static function fromConfig(): self
    {
        $blocked = array_values(array_map(
            static fn ($k): string => strtolower((string) $k),
            array_filter((array) config('tendril.privacy.blocked_keys', []), 'is_scalar'),
        ));

        $allowed = array_values(array_map(
            static fn ($k): string => (string) $k,
            array_filter((array) config('tendril.privacy.allowed_keys', []), 'is_scalar'),
        ));

        return new self(
            $blocked,
            $allowed,
            max(1, (int) config('tendril.limits.max_depth', 3)),
            max(1, (int) config('tendril.limits.max_properties', 64)),
            max(16, (int) config('tendril.limits.max_string_length', 1024)),
        );
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function sanitize(array $input, array &$warnings = [], bool $applyAllowList = true): array
    {
        if ($applyAllowList && $this->allowed !== []) {
            $input = array_intersect_key($input, array_flip($this->allowed));
        }

        $leaves = 0;

        return $this->walk($input, 1, $leaves, $warnings);
    }

    public function isBlocked(string $key): bool
    {
        $lower = strtolower($key);

        foreach ($this->blocked as $fragment) {
            if ($fragment !== '' && str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function walk(array $input, int $depth, int &$leaves, array &$warnings): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if ($key === '' || strlen($key) > 64 || preg_match('/^[A-Za-z0-9_\-]+$/', $key) !== 1) {
                $warnings[] = 'invalid_key';

                continue;
            }

            if ($this->isBlocked($key)) {
                $warnings[] = 'blocked_key';

                continue;
            }

            if (is_array($value)) {
                if ($depth >= $this->maxDepth) {
                    $warnings[] = 'max_depth';

                    continue;
                }

                $child = $this->walk($value, $depth + 1, $leaves, $warnings);

                if ($child !== []) {
                    $out[$key] = array_is_list($value) ? array_values($child) : $child;
                }

                continue;
            }

            $scalar = $this->scalar($value);

            if ($scalar === null) {
                if ($value !== null) {
                    $warnings[] = 'unsupported_value';
                }

                continue;
            }

            if ($leaves >= $this->maxLeaves) {
                $warnings[] = 'max_properties';

                continue;
            }

            $leaves++;
            $out[$key] = $scalar;
        }

        return $out;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_strlen($value) > $this->maxStringLength
                ? mb_substr($value, 0, $this->maxStringLength)
                : $value;
        }

        if (is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        }

        if ($value instanceof Stringable && ! $value instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->scalar((string) $value);
        }

        return null;
    }
}
