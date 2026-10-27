<?php

namespace VitisStudio\Tendril\Destinations;

use Closure;
use InvalidArgumentException;
use VitisStudio\Tendril\Destinations\Adapters\GoogleAdsDestination;
use VitisStudio\Tendril\Destinations\Adapters\MetaConversionsApiDestination;
use VitisStudio\Tendril\Destinations\Adapters\WebhookDestination;
use VitisStudio\Tendril\Destinations\Contracts\ConversionDestination;

/**
 * Builds destinations from config. Custom drivers: DestinationManager::extend().
 */
final class DestinationManager
{
    /** @var array<string, Closure(string, array<string, mixed>): ConversionDestination> */
    private array $drivers = [];

    /**
     * @param  Closure(string, array<string, mixed>): ConversionDestination  $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->drivers[$driver] = $factory;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function configured(): array
    {
        $out = [];

        foreach ((array) config('tendril-destinations.destinations', []) as $name => $config) {
            if (is_string($name) && is_array($config) && ($config['enabled'] ?? true)) {
                $out[$name] = $config;
            }
        }

        return $out;
    }

    public function make(string $name): ConversionDestination
    {
        $config = $this->configured()[$name] ?? null;

        if ($config === null) {
            throw new InvalidArgumentException("Destination [{$name}] is not configured.");
        }

        $driver = (string) ($config['driver'] ?? '');

        if (isset($this->drivers[$driver])) {
            return ($this->drivers[$driver])($name, $config);
        }

        return match ($driver) {
            'webhook' => new WebhookDestination($name, $config),
            'meta' => new MetaConversionsApiDestination($name, $config),
            'google_ads' => new GoogleAdsDestination($name, $config),
            default => throw new InvalidArgumentException("Unknown destination driver [{$driver}]."),
        };
    }

    /**
     * Conversion key => destination event/action name.
     *
     * @return array<string, string>
     */
    public function mapping(string $name): array
    {
        $map = [];

        foreach ((array) ($this->configured()[$name]['conversions'] ?? []) as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $map[$value] = $value;
            } elseif (is_string($key) && is_string($value)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
