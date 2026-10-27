<?php

namespace VitisStudio\Tendril\Marketing\Conversions;

use InvalidArgumentException;
use Throwable;
use VitisStudio\Tendril\Marketing\Models\Conversion;

/**
 * Data definitions (Filament/JSON) plus code definitions; code wins on key collisions.
 */
final class ConversionRepository
{
    public function __construct(private readonly ConversionRegistry $registry) {}

    /**
     * @return array<string, ConversionDefinition>
     */
    public function all(bool $includeInactive = false): array
    {
        $out = [];

        try {
            $query = Conversion::query()->orderBy('key');

            if (! $includeInactive) {
                $query->where('active', true);
            }

            foreach ($query->get() as $row) {
                try {
                    $out[$row->key] = ConversionDefinition::fromArray([
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
            // Not migrated yet.
        }

        foreach ($this->registry->all() as $key => $conversion) {
            $out[$key] = $conversion;
        }

        ksort($out);

        return $out;
    }

    public function find(string $key): ?ConversionDefinition
    {
        return $this->all(true)[$key] ?? null;
    }

    public function isCodeDefined(string $key): bool
    {
        return $this->registry->get($key) !== null;
    }

    public function save(ConversionDefinition $conversion, bool $active = true): Conversion
    {
        if ($this->isCodeDefined($conversion->key)) {
            throw new InvalidArgumentException("Conversion [{$conversion->key}] is defined in code and cannot be edited as data.");
        }

        /** @var Conversion $model */
        $model = Conversion::query()->firstOrNew(['key' => $conversion->key]);
        $model->fill([
            'name' => $conversion->name,
            'description' => $conversion->description,
            'definition' => $conversion->definition(),
            'active' => $active,
        ]);
        $model->save();

        return $model;
    }

    public function export(): string
    {
        return (string) json_encode([
            'version' => 1,
            'conversions' => array_values(array_map(fn (ConversionDefinition $c) => $c->toArray(), $this->all(true))),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function import(string $json, bool $overwrite = false): int
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_array($data['conversions'] ?? null)) {
            throw new InvalidArgumentException('Expected {"conversions": [...]} JSON.');
        }

        $count = 0;

        foreach ($data['conversions'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $conversion = ConversionDefinition::fromArray($entry);

            if ($this->isCodeDefined($conversion->key) || (! $overwrite && Conversion::query()->where('key', $conversion->key)->exists())) {
                continue;
            }

            $this->save($conversion);
            $count++;
        }

        return $count;
    }

    public function hash(): string
    {
        return sha1(implode('|', array_map(fn (ConversionDefinition $c) => $c->hash(), $this->all())));
    }
}
