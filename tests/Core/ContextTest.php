<?php

use Illuminate\Support\Facades\Context;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Identity\ActorRef;

it('keeps ids visible for logs and identity-adjacent values hidden', function () {
    $store = app(ContextStore::class);
    $requestId = $store->begin('http', null, 'trace-123456');
    $store->setVisitor('01J00000000000000000000000', 'anon-1');
    $store->setActor(new ActorRef('App\\Models\\User', '7'));

    expect(Context::get(ContextStore::REQUEST_ID))->toBe($requestId)
        ->and(Context::get(ContextStore::TRACE_ID))->toBe('trace-123456')
        ->and(Context::all())->not->toHaveKey(ContextStore::ANONYMOUS_ID)
        ->and(Context::all())->not->toHaveKey(ContextStore::ACTOR)
        ->and(Context::getHidden(ContextStore::ANONYMOUS_ID))->toBe('anon-1')
        ->and(Context::getHidden(ContextStore::ACTOR))->toBe(['type' => 'App\\Models\\User', 'id' => '7']);
});

it('clears only its own keys', function () {
    Context::add('host.key', 'kept');
    $store = app(ContextStore::class);
    $store->begin('http');
    $store->setVisitor('01J00000000000000000000000', 'anon-1');
    $store->clear();

    expect($store->snapshot())->toBe([])
        ->and(Context::get('host.key'))->toBe('kept');
});

it('merges scoped context into events and restores it afterwards', function () {
    Tendril::withContext(['workflow' => 'renewal', 'route' => 'spoofed', 'bad key' => 'x'], function () {
        Tendril::track('invoice.sent');

        Tendril::withContext(['step' => 2], fn () => Tendril::track('invoice.reminded'));
    });

    Tendril::track('outside.scope');
    Tendril::flush();

    $events = storedEvents()->keyBy('name');

    // toEqual: PostgreSQL jsonb and MySQL JSON do not preserve key order.
    expect($events['invoice.sent']->context)->toEqual(['workflow' => 'renewal'])
        ->and($events['invoice.reminded']->context)->toEqual(['workflow' => 'renewal', 'step' => 2])
        ->and($events['outside.scope']->context)->toBeEmpty();
});

it('links parent events through the Context stack with a depth cap', function () {
    config(['tendril.context.max_depth' => 3]);

    $parent = Tendril::track('checkout.started');

    Tendril::withParent($parent, function () {
        $child = Tendril::track('payment.authorized');

        Tendril::withParent($child, function () {
            Tendril::track('receipt.sent');
            // Cap reached: this push is ignored, parent stays the child.
            Tendril::withParent('01J99999999999999999999999', fn () => Tendril::track('deep.event'));
        });
    });

    Tendril::flush();
    $events = storedEvents()->keyBy('name');

    expect($events['checkout.started']->parent_id)->toBeNull()
        ->and($events['payment.authorized']->parent_id)->toBe($parent)
        ->and($events['receipt.sent']->parent_id)->toBe($events['payment.authorized']->id)
        ->and($events['deep.event']->parent_id)->toBe($events['payment.authorized']->id)
        ->and($events->pluck('request_id')->unique())->toHaveCount(1);
});

it('gives commands and ad-hoc work a synthetic root with no actor', function () {
    Tendril::track('nightly.report');
    Tendril::flush();

    $event = storedEvents()->first();

    expect($event->request_id)->not->toBeNull()
        ->and($event->actor_id)->toBeNull()
        ->and($event->source)->toBe('console');
});
