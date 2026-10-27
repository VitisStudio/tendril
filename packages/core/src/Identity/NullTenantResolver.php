<?php

namespace VitisStudio\Tendril\Core\Identity;

use VitisStudio\Tendril\Core\Contracts\TenantResolver;

final class NullTenantResolver implements TenantResolver
{
    public function resolve(): ?TenantRef
    {
        return null;
    }
}
