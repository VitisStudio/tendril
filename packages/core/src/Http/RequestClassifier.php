<?php

namespace VitisStudio\Tendril\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which requests are page views.
 *
 * - wire:navigate fetches (X-Livewire-Navigate) are page views ("navigate").
 * - Prefetches (Purpose/Sec-Purpose: prefetch, …) are not.
 * - Livewire update requests (X-Livewire) are not: actions are captured by the hook.
 * - Only successful, non-redirect HTML GET responses count.
 */
final class RequestClassifier
{
    public static function isLivewireUpdate(Request $request): bool
    {
        return $request->headers->has('X-Livewire') && ! self::isNavigate($request);
    }

    public static function isNavigate(Request $request): bool
    {
        return $request->headers->has('X-Livewire-Navigate');
    }

    public static function isPrefetch(Request $request): bool
    {
        foreach ((array) config('tendril.http.prefetch_headers', []) as $header) {
            if (! is_string($header)) {
                continue;
            }

            $value = $request->headers->get($header);

            if (is_string($value) && str_contains(strtolower($value), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    public static function navigation(Request $request): string
    {
        return self::isNavigate($request) ? 'navigate' : 'full';
    }

    public static function isPageView(Request $request, Response $response, ?string $route): bool
    {
        if (! config('tendril.http.track_page_views', true) || $route === null) {
            return false;
        }

        if (! $request->isMethod('GET') || self::isPrefetch($request) || self::isLivewireUpdate($request)) {
            return false;
        }

        if (! self::isNavigate($request) && ($request->ajax() || $request->expectsJson())) {
            return false;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return false;
        }

        $type = $response->headers->get('Content-Type');

        if (is_string($type) && $type !== '' && ! str_contains(strtolower($type), 'text/html')) {
            return false;
        }

        if (self::excluded($request, $route)) {
            return false;
        }

        return true;
    }

    public static function excluded(Request $request, string $route): bool
    {
        $routes = array_values(array_filter((array) config('tendril.http.exclude_routes', []), 'is_string'));
        $paths = array_values(array_filter((array) config('tendril.http.exclude_paths', []), 'is_string'));

        return ($routes !== [] && Str::is($routes, $route))
            || ($paths !== [] && Str::is($paths, trim($request->path(), '/')));
    }
}
