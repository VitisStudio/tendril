<?php

namespace VitisStudio\Tendril\Filament\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Filament\TendrilPlugin;

/**
 * Authorization and tenant scope for every Tendril page and widget.
 */
final class TendrilFilament
{
    public const string GATE = 'viewTendril';

    public const string MANAGE_GATE = 'manageTendril';

    public static function plugin(): ?TendrilPlugin
    {
        try {
            $panel = Filament::getCurrentPanel();

            if ($panel !== null && $panel->hasPlugin('tendril')) {
                $plugin = $panel->getPlugin('tendril');

                return $plugin instanceof TendrilPlugin ? $plugin : null;
            }
        } catch (Throwable) {
            // No panel context.
        }

        return null;
    }

    public static function user(): ?Authenticatable
    {
        try {
            $user = Filament::auth()->user();
        } catch (Throwable) {
            $user = null;
        }

        $user ??= auth()->user();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Plugin callback when configured, else the viewTendril gate. Guests never pass.
     */
    public static function authorized(): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        $callback = self::plugin()?->getAuthorizeUsing();

        if ($callback !== null) {
            return (bool) $callback($user);
        }

        return Gate::forUser($user)->allows(self::GATE);
    }

    /**
     * Editing definitions (features, funnels, cohorts, conversions): the
     * "manageTendril" gate when the host defines it, else view authorization.
     */
    public static function canManage(): bool
    {
        if (! self::authorized()) {
            return false;
        }

        $user = self::user();

        return ! Gate::has(self::MANAGE_GATE) || ($user !== null && Gate::forUser($user)->allows(self::MANAGE_GATE));
    }

    /**
     * Report scope. Order: plugin override, Filament panel tenant (fail closed
     * when the panel has tenancy but no tenant), then the core tenant
     * resolver, else all tenants.
     */
    public static function scope(): TenantScope
    {
        $override = self::plugin()?->getTenantScopeUsing();

        if ($override !== null) {
            $value = $override();

            if ($value instanceof TenantScope) {
                return $value;
            }

            return TenantScope::fromNullable(TenantRef::tryFrom($value));
        }

        try {
            if (Filament::hasTenancy()) {
                $tenant = Filament::getTenant();

                return $tenant !== null ? TenantScope::tenant(TenantRef::from($tenant)) : TenantScope::none();
            }
        } catch (Throwable) {
            return TenantScope::none();
        }

        return TenantScope::fromNullable(app(TenantResolver::class)->resolve());
    }
}
