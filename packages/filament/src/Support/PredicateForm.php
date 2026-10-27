<?php

namespace VitisStudio\Tendril\Filament\Support;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use VitisStudio\Tendril\Core\Exceptions\InvalidPredicate;
use VitisStudio\Tendril\Core\Query\Predicate;
use VitisStudio\Tendril\Core\Query\PredicateParser;

/**
 * Maps a flat "all conditions" form repeater to and from the predicate DSL.
 * Serialized output is always validated by the DSL parser before saving.
 */
final class PredicateForm
{
    public static function repeater(string $name = 'conditions'): Repeater
    {
        return Repeater::make($name)
            ->label('Where (all conditions must match)')
            ->schema([
                TextInput::make('field')
                    ->label('Field')
                    ->placeholder('properties.plan')
                    ->required()
                    ->maxLength(120),
                Select::make('op')
                    ->label('Operator')
                    ->options(array_combine(PredicateParser::OPERATORS, PredicateParser::OPERATORS))
                    ->default('eq')
                    ->required(),
                Select::make('type')
                    ->label('Value type')
                    ->options(['string' => 'Text', 'number' => 'Number', 'boolean' => 'Boolean', 'list' => 'List (comma separated)'])
                    ->default('string')
                    ->required(),
                TextInput::make('value')
                    ->label('Value')
                    ->maxLength(1000),
            ])
            ->defaultItems(0)
            ->columns(4);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<string, mixed>|null normalized predicate tree, null when empty
     *
     * @throws InvalidPredicate
     */
    public static function toPredicate(?array $rows): ?array
    {
        $conditions = [];

        foreach ($rows ?? [] as $row) {
            $field = trim((string) ($row['field'] ?? ''));
            $op = (string) ($row['op'] ?? 'eq');

            if ($field === '') {
                continue;
            }

            $condition = ['field' => $field, 'op' => $op];

            if (! in_array($op, ['exists', 'not_exists'], true)) {
                $condition['value'] = self::value((string) ($row['type'] ?? 'string'), $row['value'] ?? null, $op);
            }

            $conditions[] = $condition;
        }

        if ($conditions === []) {
            return null;
        }

        return Predicate::fromArray(['all' => $conditions])->toArray();
    }

    /**
     * @param  array<string, mixed>|null  $tree
     * @return list<array<string, mixed>>
     */
    public static function fromPredicate(?array $tree): array
    {
        $rows = [];

        foreach ((array) ($tree['all'] ?? []) as $condition) {
            if (! is_array($condition) || ! isset($condition['field'], $condition['op'])) {
                continue;
            }

            $value = $condition['value'] ?? null;
            $type = match (true) {
                is_array($value) => 'list',
                is_bool($value) => 'boolean',
                is_int($value) || is_float($value) => 'number',
                default => 'string',
            };

            $rows[] = [
                'field' => $condition['field'],
                'op' => $condition['op'],
                'type' => $type,
                'value' => match ($type) {
                    'list' => implode(', ', array_map('strval', (array) $value)),
                    'boolean' => $value ? 'true' : 'false',
                    default => $value === null ? null : (string) $value,
                },
            ];
        }

        return $rows;
    }

    private static function value(string $type, mixed $raw, string $op): mixed
    {
        $raw = is_scalar($raw) ? trim((string) $raw) : '';

        return match ($type) {
            'number' => str_contains($raw, '.') ? (float) $raw : (int) $raw,
            'boolean' => in_array(strtolower($raw), ['1', 'true', 'yes'], true),
            'list' => array_values(array_map(
                fn (string $v) => is_numeric($v) && in_array($op, ['in', 'not_in'], true) && self::allNumeric($raw) ? (str_contains($v, '.') ? (float) $v : (int) $v) : $v,
                array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''),
            )),
            default => $raw,
        };
    }

    private static function allNumeric(string $raw): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '') as $v) {
            if (! is_numeric($v)) {
                return false;
            }
        }

        return true;
    }
}
