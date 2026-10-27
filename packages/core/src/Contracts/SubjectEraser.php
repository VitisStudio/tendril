<?php

namespace VitisStudio\Tendril\Core\Contracts;

use VitisStudio\Tendril\Core\Identity\ActorRef;

/**
 * Packages that store identity-bearing derived data (activity tables,
 * destination deliveries…) register an eraser tagged "tendril.erasers".
 */
interface SubjectEraser
{
    /**
     * @param  list<string>  $anonymousIds
     * @return int rows affected
     */
    public function erase(?ActorRef $actor, array $anonymousIds): int;
}
