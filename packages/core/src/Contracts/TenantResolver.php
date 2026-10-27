<?php

namespace VitisStudio\Tendril\Core\Contracts;

use VitisStudio\Tendril\Core\Identity\TenantRef;

interface TenantResolver
{
    /**
     * The tenant for the current unit of work, or null for tenant-less apps.
     */
    public function resolve(): ?TenantRef;
}
