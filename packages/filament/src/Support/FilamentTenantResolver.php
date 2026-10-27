<?php

namespace VitisStudio\Tendril\Filament\Support;

use Filament\Facades\Filament;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Core\Identity\TenantRef;

/**
 * Decorates the core tenant resolver during capture: inside a Filament panel
 * with tenancy, events carry the panel's current tenant with zero config;
 * elsewhere the host's resolver decides.
 */
final class FilamentTenantResolver implements TenantResolver
{
    public function __construct(private readonly TenantResolver $inner) {}

    public function resolve(): ?TenantRef
    {
        try {
            $tenant = Filament::getTenant();

            if ($tenant !== null) {
                return TenantRef::from($tenant);
            }
        } catch (Throwable) {
            // Not in a panel context.
        }

        return $this->inner->resolve();
    }
}
