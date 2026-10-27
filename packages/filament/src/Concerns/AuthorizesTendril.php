<?php

namespace VitisStudio\Tendril\Filament\Concerns;

use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;

/**
 * Livewire trait hook: re-checked on every request (initial render and every
 * subsequent Livewire update), not only when the route is first hit. Uses
 * the page's canAccess() / widget's canView() so per-page rules also hold
 * for direct Livewire calls.
 */
trait AuthorizesTendril
{
    public function bootAuthorizesTendril(): void
    {
        $allowed = match (true) {
            method_exists(static::class, 'canAccess') => (bool) static::canAccess(),
            method_exists(static::class, 'canView') => (bool) static::canView(),
            default => TendrilFilament::authorized(),
        };

        abort_unless($allowed && TendrilFilament::authorized(), 403);
    }

    protected function tenantScope(): TenantScope
    {
        return TendrilFilament::scope();
    }
}
