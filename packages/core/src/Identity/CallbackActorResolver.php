<?php

namespace VitisStudio\Tendril\Core\Identity;

use Closure;
use VitisStudio\Tendril\Core\Contracts\ActorResolver;

final class CallbackActorResolver implements ActorResolver
{
    /**
     * @param  Closure(): mixed  $callback  returning a model, reference or null
     */
    public function __construct(private readonly Closure $callback) {}

    public function resolve(): ?ActorRef
    {
        return ActorRef::tryFrom(($this->callback)());
    }
}
