<?php

namespace VitisStudio\Tendril\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Low-cardinality route identity: the route name, else the URI pattern
 * ("users/{user}"), never the resolved path.
 */
final class RouteNamer
{
    public static function for(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $name = $route->getName();

        if (is_string($name) && $name !== '' && ! str_starts_with($name, 'generated::')) {
            return substr($name, 0, 191);
        }

        return substr('/'.ltrim($route->uri(), '/'), 0, 191);
    }

    public static function forRequest(Request $request): ?string
    {
        $route = $request->route();

        return $route instanceof Route ? self::for($route) : null;
    }

    /**
     * Resolve a path (e.g. from the beacon) to its route identity.
     */
    public static function forPath(string $path, string $method = 'GET'): ?string
    {
        try {
            $request = Request::create('/'.ltrim($path, '/'), $method);
            $route = app('router')->getRoutes()->match($request);

            return self::for($route);
        } catch (Throwable) {
            return null;
        }
    }
}
