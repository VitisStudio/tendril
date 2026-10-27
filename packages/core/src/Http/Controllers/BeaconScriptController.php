<?php

namespace VitisStudio\Tendril\Core\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the dependency-free beacon script.
 */
final class BeaconScriptController
{
    public function __invoke(): Response
    {
        if (! config('tendril.beacon.enabled', false)) {
            abort(404);
        }

        $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/tendril-beacon.js');

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
