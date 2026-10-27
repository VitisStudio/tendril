<?php

namespace VitisStudio\Tendril\Core\Identity;

use Illuminate\Http\Request;
use VitisStudio\Tendril\Core\Contracts\ConsentResolver;

/**
 * Default: no consent. Keeps the package cookieless and click-ID free until the
 * host wires its own consent state.
 */
final class DenyConsentResolver implements ConsentResolver
{
    public function hasAnalyticsConsent(Request $request): bool
    {
        return false;
    }
}
