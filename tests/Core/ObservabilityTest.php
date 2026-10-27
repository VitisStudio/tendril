<?php

use VitisStudio\Tendril\Core\Facades\Tendril;

it('builds observability pivots from correlation ids only', function () {
    config(['tendril.observability.links' => [
        'logs' => 'https://logs.example.test/search?q=request:{request_id}',
        'trace' => 'https://trace.example.test/{trace_id}',
    ]]);

    $this->get('/', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
    $event = storedEvents('page.viewed')->sole();

    expect(Tendril::observabilityLinks($event))->toBe([
        'logs' => 'https://logs.example.test/search?q=request:'.$event->request_id,
        'trace' => 'https://trace.example.test/4bf92f3577b34da6a3ce929d0e0e4736',
    ]);

    // Templates whose ids are missing are skipped rather than half-filled.
    $this->get('/pricing');

    expect(Tendril::observabilityLinks(storedEvents('page.viewed')->last()))->toHaveKeys(['logs'])->not->toHaveKey('trace');
});
