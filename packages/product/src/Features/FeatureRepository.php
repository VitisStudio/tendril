<?php

namespace VitisStudio\Tendril\Product\Features;

use InvalidArgumentException;
use Throwable;
use VitisStudio\Tendril\Product\Models\Feature;

/**
 * All feature definitions: data rows (Filament/JSON import) plus code
 * definitions. Code definitions win on key collisions (version-controlled
 * teams); the UI shows them read-only.
 */
final class FeatureRepository
{
    public function __construct(private readonly FeatureRegistry $registry) {}

    /**
     * @return array<string, FeatureDefinition>
     */
    public function all(bool $includeInactive = false): array
    {
        $features = [];

        try {
            $query = Feature::query()->orderBy('key');

            if (! $includeInactive) {
                $query->where('active', true);
            }

            foreach ($query->get() as $row) {
                try {
                    $features[$row->key] = FeatureDefinition::fromArray([
                        'key' => $row->key,
                        'name' => $row->name,
                        'description' => $row->description,
                        'definition' => $row->definition,
                    ]);
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        } catch (Throwable) {
            // Table missing (not migrated yet): code definitions still work.
        }

        foreach ($this->registry->all() as $key => $feature) {
            $features[$key] = $feature;
        }

        ksort($features);

        return $features;
    }

    public function find(string $key): ?FeatureDefinition
    {
        return $this->all(true)[$key] ?? null;
    }

    public function isCodeDefined(string $key): bool
    {
        return $this->registry->get($key) !== null;
    }

    public function save(FeatureDefinition $feature, bool $active = true): Feature
    {
        if ($this->isCodeDefined($feature->key)) {
            throw new InvalidArgumentException("Feature [{$feature->key}] is defined in code and cannot be edited as data.");
        }

        /** @var Feature $model */
        $model = Feature::query()->firstOrNew(['key' => $feature->key]);
        $model->fill([
            'name' => $feature->name,
            'description' => $feature->description,
            'definition' => $feature->definition(),
            'active' => $active,
        ]);
        $model->save();

        return $model;
    }

    public function delete(string $key): void
    {
        Feature::query()->where('key', $key)->delete();
    }

    public function export(): string
    {
        return (string) json_encode([
            'version' => 1,
            'features' => array_values(array_map(fn (FeatureDefinition $f) => $f->toArray(), $this->all(true))),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return int features imported
     */
    public function import(string $json, bool $overwrite = false): int
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_array($data['features'] ?? null)) {
            throw new InvalidArgumentException('Expected {"features": [...]} JSON.');
        }

        $count = 0;

        foreach ($data['features'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $feature = FeatureDefinition::fromArray($entry);

            if ($this->isCodeDefined($feature->key) || (! $overwrite && Feature::query()->where('key', $feature->key)->exists())) {
                continue;
            }

            $this->save($feature);
            $count++;
        }

        return $count;
    }

    public function hash(): string
    {
        return sha1(implode('|', array_map(fn (FeatureDefinition $f) => $f->hash(), $this->all())));
    }
}
