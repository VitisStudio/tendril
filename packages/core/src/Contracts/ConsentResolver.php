<?php

namespace VitisStudio\Tendril\Core\Contracts;

use Illuminate\Http\Request;

/**
 * Host-owned consent state. Gates the persistent anonymous cookie and click-ID storage.
 */
interface ConsentResolver
{
    public function hasAnalyticsConsent(Request $request): bool;
}
