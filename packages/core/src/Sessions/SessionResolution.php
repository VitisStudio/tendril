<?php

namespace VitisStudio\Tendril\Core\Sessions;

use VitisStudio\Tendril\Core\Acquisition\Acquisition;

/**
 * Result of resolving the analytics session for one request.
 */
final class SessionResolution
{
    public function __construct(
        public readonly SessionState $state,
        public readonly bool $started,
        public readonly Acquisition $acquisition,
        public readonly ?string $landingRoute,
        /** null = leave cookie alone, true = (re)issue, false = forget */
        public readonly ?bool $cookie,
    ) {}
}
