<?php

namespace VitisStudio\Tendril\Filament\Pages;

use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;

/**
 * Effective collection, retention, filter, privacy, presence and driver
 * settings. Infrastructure belongs in config/code, so this page is read-only;
 * secrets are redacted.
 */
class Settings extends TendrilPage
{
    public const array SECTIONS = [
        'Collection' => ['enabled', 'environment', 'http', 'livewire', 'auth', 'beacon'],
        'Drivers' => ['database', 'transport', 'buffer', 'storage', 'stats'],
        'Privacy' => ['privacy', 'identity', 'acquisition', 'sessions', 'decisions'],
        'Retention' => ['retention'],
        'Presence' => ['presence'],
        'Limits' => ['limits'],
    ];

    public static function tendrilKey(): string
    {
        return 'settings';
    }

    public static function tendrilLabel(): string
    {
        return 'Settings';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public static function tendrilSort(): int
    {
        return 9;
    }

    public static function canAccess(): bool
    {
        return TendrilFilament::canManage();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function settings(): array
    {
        $out = [];

        foreach (self::SECTIONS as $section => $keys) {
            foreach ($keys as $key) {
                foreach (self::flatten($key, config("tendril.{$key}")) as $path => $value) {
                    $out[$section][$path] = $value;
                }
            }
        }

        $out['Drivers']['storage.effective_adapter'] = app(MaintenanceManager::class)->adapter()->name();

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function flatten(string $prefix, mixed $value): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            $out = [];

            foreach ($value as $key => $child) {
                $out += self::flatten("{$prefix}.{$key}", $child);
            }

            return $out;
        }

        if (preg_match('/secret|password|token|key$/i', $prefix) === 1 && $value !== null && $value !== '') {
            return [$prefix => '••••••'];
        }

        return [$prefix => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : (is_array($v) ? (string) json_encode($v) : get_debug_type($v)), $value)),
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        }];
    }
}
