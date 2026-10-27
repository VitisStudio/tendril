<?php

namespace VitisStudio\Tendril\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VitisStudio\Tendril\Core\Http\RequestLifecycle;

/**
 * Terminable capture middleware. Place it after StartSession (the package
 * appends it to the configured middleware groups). All work that touches
 * storage happens in terminate(), after the response has been sent.
 */
final class TrackRequests
{
    public const string HANDLED = 'tendril.handled';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(self::HANDLED) === true) {
            return $next($request);
        }

        $request->attributes->set(self::HANDLED, true);

        $lifecycle = app(RequestLifecycle::class);
        $lifecycle->begin($request);

        /** @var Response $response */
        $response = $next($request);

        $lifecycle->afterController($request, $response);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        app(RequestLifecycle::class)->terminate($request, $response);
    }
}
