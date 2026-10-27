<?php

namespace VitisStudio\Tendril\Core\Contracts;

use VitisStudio\Tendril\Core\Identity\ActorRef;

interface ActorResolver
{
    /**
     * The actor for the current unit of work, or null when anonymous.
     */
    public function resolve(): ?ActorRef;
}
