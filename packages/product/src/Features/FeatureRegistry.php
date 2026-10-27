<?php

namespace VitisStudio\Tendril\Product\Features;

/**
 * Code-defined features (Tendril::feature(...)). Populated at boot; treated
 * as configuration, so it is safe to share across Octane requests.
 */
final class FeatureRegistry
{
    /** @var array<string, FeatureDefinition> */
    private array $features = [];

    public function put(FeatureDefinition $feature): void
    {
        $this->features[$feature->key] = $feature;
    }

    public function get(string $key): ?FeatureDefinition
    {
        return $this->features[$key] ?? null;
    }

    /**
     * @return array<string, FeatureDefinition>
     */
    public function all(): array
    {
        return $this->features;
    }

    public function forget(string $key): void
    {
        unset($this->features[$key]);
    }
}
