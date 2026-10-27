<?php

namespace VitisStudio\Tendril\Core\Identity;

use Closure;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;

final class CallbackTenantResolver implements TenantResolver
{
    /**
     * @param  Closure(): mixed  $callback  returning a model, reference or null
     */
    public function __construct(private readonly Closure $callback) {}

    public function resolve(): ?TenantRef
    {
        return TenantRef::tryFrom(($this->callback)());
    }
}
