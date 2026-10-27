<?php

namespace VitisStudio\Tendril\Product\Aggregation;

use Closure;

/**
 * Processors run for every dirty bucket. Product registers events/features,
 * sessions and activity; marketing registers conversions.
 */
final class ProcessorRegistry
{
    /** @var array<string, Closure(): BucketProcessor> */
    private array $processors = [];

    /**
     * @param  Closure(): BucketProcessor  $factory
     */
    public function register(string $key, Closure $factory): void
    {
        $this->processors[$key] = $factory;
    }

    /**
     * @return list<BucketProcessor>
     */
    public function all(): array
    {
        return array_values(array_map(fn (Closure $factory) => $factory(), $this->processors));
    }
}
