<?php

/*
 * Minimal stand-ins for Laravel Octane's lifecycle events so the isolation
 * tests run without installing Octane or Swoole/FrankenPHP. When Octane is
 * installed, its real classes are used instead.
 */

namespace Laravel\Octane\Events;

if (! class_exists(RequestReceived::class)) {
    final class RequestReceived
    {
        public function __construct(public mixed $app = null, public mixed $sandbox = null, public mixed $request = null) {}
    }
}

if (! class_exists(RequestTerminated::class)) {
    final class RequestTerminated
    {
        public function __construct(public mixed $app = null, public mixed $sandbox = null, public mixed $request = null, public mixed $response = null) {}
    }
}
