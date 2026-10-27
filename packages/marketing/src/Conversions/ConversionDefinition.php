<?php

namespace VitisStudio\Tendril\Marketing\Conversions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use VitisStudio\Tendril\Core\Capture\EventNormalizer;
use VitisStudio\Tendril\Core\Query\Predicate;
use VitisStudio\Tendril\Core\Support\JsonSql;
use VitisStudio\Tendril\Marketing\Money\Money;

/**
 * A conversion is a definition over facts:
 *   event + optional predicate + optional value (property path) + currency.
 * Created at any time and evaluated historically.
 */
final class ConversionDefinition implements JsonSerializable
{
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,99}$/';

    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $event,
        public readonly Predicate $where,
        public readonly ?string $valuePath = null,
        public readonly ?string $currency = null,
        public readonly ?string $currencyPath = null,
        public readonly bool $valueInMinorUnits = false,
        public readonly ?string $description = null,
        public readonly string $source = 'data',
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid conversion key [{$key}].");
        }

        if (preg_match(EventNormalizer::NAME_PATTERN, $event) !== 1) {
            throw new InvalidArgumentException("Invalid event name [{$event}].");
        }

        foreach ([$valuePath, $currencyPath] as $path) {
            if ($path !== null) {
                self::assertPropertyPath($path);
            }
        }

        if ($currency !== null && Money::normalizeCurrency($currency) === null) {
            throw new InvalidArgumentException("Invalid currency [{$currency}].");
        }
    }

    public static function make(string $key, string $source = 'code'): self
    {
        return new self($key, Str::headline($key), 'conversion.completed', Predicate::always(), source: $source);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $source = 'data'): self
    {
        $d = is_array($data['definition'] ?? null) ? $data['definition'] : $data;

        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['name'] ?? Str::headline((string) ($data['key'] ?? ''))),
            strtolower((string) ($d['event'] ?? '')),
            Predicate::fromArray(is_array($d['where'] ?? null) ? $d['where'] : null),
            self::nullableString($d['value_path'] ?? null),
            self::nullableString($d['currency'] ?? null) !== null ? strtoupper((string) $d['currency']) : null,
            self::nullableString($d['currency_path'] ?? null),
            (bool) ($d['value_in_minor_units'] ?? false),
            self::nullableString($data['description'] ?? null),
            $source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => $this->event,
            'where' => $this->where->isEmpty() ? null : $this->where->toArray(),
            'value_path' => $this->valuePath,
            'currency' => $this->currency,
            'currency_path' => $this->currencyPath,
            'value_in_minor_units' => $this->valueInMinorUnits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'description' => $this->description, 'definition' => $this->definition()];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function hash(): string
    {
        return sha1((string) json_encode([$this->key, $this->definition()]));
    }

    public function hasValue(): bool
    {
        return $this->valuePath !== null;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{0: ?int, 1: ?string} [minor units, currency]
     */
    public function valueFrom(array $properties): array
    {
        if ($this->valuePath === null) {
            return [null, null];
        }

        $raw = data_get(['properties' => $properties], $this->valuePath);

        if (! is_int($raw) && ! is_float($raw) && ! (is_string($raw) && is_numeric($raw))) {
            return [null, null];
        }

        $currency = $this->currency
            ?? ($this->currencyPath !== null ? Money::normalizeCurrency(data_get(['properties' => $properties], $this->currencyPath)) : null)
            ?? (string) config('tendril-marketing.default_currency', 'USD');

        if ($this->valueInMinorUnits) {
            return [(int) $raw, $currency];
        }

        try {
            return [Money::toMinor(is_string($raw) ? $raw : $raw, $currency), $currency];
        } catch (InvalidArgumentException) {
            return [null, null];
        }
    }

    public function with(string $attribute, mixed $value): self
    {
        $data = $this->toArray();

        if (in_array($attribute, ['name', 'description'], true)) {
            $data[$attribute] = $value;
        } else {
            $data['definition'][$attribute] = $value instanceof Predicate ? $value->toArray() : $value;
        }

        return self::fromArray($data, $this->source);
    }

    private static function assertPropertyPath(string $path): void
    {
        $parts = explode('.', $path);

        if (array_shift($parts) !== 'properties' || $parts === []) {
            throw new InvalidArgumentException("Value paths must start with properties. ([{$path}]).");
        }

        JsonSql::assertPath($parts);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
