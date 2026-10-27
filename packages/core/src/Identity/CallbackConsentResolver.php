<?php

namespace VitisStudio\Tendril\Core\Identity;

use Closure;
use Illuminate\Http\Request;
use VitisStudio\Tendril\Core\Contracts\ConsentResolver;

/**
 * Consent from a closure, e.g. reading the host's consent cookie:
 * Tendril::consentUsing(fn (Request $r) => $r->cookie('consent') === 'all').
 */
final class CallbackConsentResolver implements ConsentResolver
{
    /**
     * @param  Closure(Request): bool  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function hasAnalyticsConsent(Request $request): bool
    {
        return (bool) ($this->callback)($request);
    }
}
