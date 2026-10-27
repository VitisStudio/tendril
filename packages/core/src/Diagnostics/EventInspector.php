<?php

namespace VitisStudio\Tendril\Core\Diagnostics;

use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Event catalog entry: volume, versions, sources and an inferred property
 * schema from a bounded sample of recent events (index: name, occurred_at).
 */
final class EventInspector
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array{name: string, count: int, versions: array<int, int>, sources: array<string, int>, schema: array<string, array{types: list<string>, present: int, distinct: int}>}
     */
    public function inspect(string $name, int $days = 7, int $sample = 500, ?TenantScope $scope = null): array
    {
        $name = strtolower($name);
        $scope ??= TenantScope::all();
        $since = $this->db->time(Instant::now()->subDays(max(1, $days)));
        $base = fn () => $scope->applyToRaw($this->db->table(Tables::EVENTS))->where('name', $name)->where('occurred_at', '>=', $since);

        $versions = [];

        foreach ($base()->select('version')->selectRaw('count(*) as aggregate')->groupBy('version')->orderBy('version')->get() as $row) {
            $versions[(int) $row->version] = (int) $row->aggregate;
        }

        $sources = [];

        foreach ($base()->select('source')->selectRaw('count(*) as aggregate')->groupBy('source')->get() as $row) {
            $sources[(string) $row->source] = (int) $row->aggregate;
        }

        $schema = [];

        foreach ($base()->orderByDesc('occurred_at')->limit(max(1, $sample))->pluck('properties') as $json) {
            $properties = is_string($json) ? json_decode($json, true) : null;

            foreach (is_array($properties) ? self::flatten($properties) : [] as $key => $value) {
                $schema[$key]['types'][get_debug_type($value)] = true;
                $schema[$key]['present'] = ($schema[$key]['present'] ?? 0) + 1;
                $schema[$key]['values'][is_scalar($value) ? (string) $value : '…'] = true;
            }
        }

        ksort($schema);

        return [
            'name' => $name,
            'count' => array_sum($versions),
            'versions' => $versions,
            'sources' => $sources,
            'schema' => array_map(fn (array $info) => [
                'types' => array_keys($info['types']),
                'present' => $info['present'],
                'distinct' => count($info['values']),
            ], $schema),
        ];
    }

    /**
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    public static function flatten(array $input, string $prefix = ''): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && ! array_is_list($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
