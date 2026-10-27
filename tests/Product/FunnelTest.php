<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Product\Funnels\FunnelDefinition;
use VitisStudio\Tendril\Product\Funnels\FunnelEngine;

function signupFunnel(int $window = 3600): FunnelDefinition
{
    return FunnelDefinition::fromArray(['key' => 'signup', 'name' => 'Signup', 'definition' => [
        'steps' => [
            ['event' => 'page.viewed', 'label' => 'Pricing', 'where' => ['all' => [['field' => 'context.route', 'op' => 'eq', 'value' => 'pricing']]]],
            ['event' => 'signup.started'],
            ['event' => 'signup.completed', 'where' => ['all' => [['field' => 'properties.plan', 'op' => 'in', 'value' => ['pro', 'team']]]]],
        ],
        'window_seconds' => $window,
    ]]);
}

beforeEach(function () {
    $this->t = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');
    $pricing = ['context' => ['route' => 'pricing']];

    // A: completes in 10 minutes.
    writeEvent('page.viewed', $this->t, actor('A', '1') + $pricing);
    writeEvent('signup.started', $this->t->addMinutes(5), actor('A', '1'));
    writeEvent('signup.completed', $this->t->addMinutes(10), actor('A', '1') + ['properties' => ['plan' => 'pro']]);

    // B: out of order (completed before started) and a free plan: reaches step 2 only.
    writeEvent('signup.completed', $this->t->addMinutes(1), actor('B', '1') + ['properties' => ['plan' => 'pro']]);
    writeEvent('page.viewed', $this->t->addMinutes(2), actor('B', '1') + $pricing);
    writeEvent('signup.started', $this->t->addMinutes(3), actor('B', '1'));
    writeEvent('signup.completed', $this->t->addMinutes(4), actor('B', '1') + ['properties' => ['plan' => 'free']]);

    // C: first attempt stalls, a later entry completes within the window.
    writeEvent('page.viewed', $this->t, actor('C', '2') + $pricing);
    writeEvent('page.viewed', $this->t->addHours(3), actor('C', '2') + $pricing);
    writeEvent('signup.started', $this->t->addHours(3)->addMinutes(1), actor('C', '2'));
    writeEvent('signup.completed', $this->t->addHours(3)->addMinutes(30), actor('C', '2') + ['properties' => ['plan' => 'team']]);

    // D: anonymous visitor, only views pricing.
    writeEvent('page.viewed', $this->t, ['anonymous_id' => 'visitor-d'] + $pricing);
});

it('computes step counts, drop-off, completion and time-to-convert', function () {
    $result = app(FunnelEngine::class)->evaluate(signupFunnel(), $this->t->startOfDay(), $this->t->endOfDay(), TenantScope::all());

    expect(array_column($result->steps, 'count'))->toBe([4, 3, 2])
        ->and($result->entered)->toBe(4)
        ->and($result->completed)->toBe(2)
        ->and($result->steps[1]['from_previous'])->toBe(0.75)
        ->and($result->completionRate())->toBe(0.5)
        ->and($result->medianSeconds)->toBe(1200.0)
        ->and($result->averageSeconds)->toBe(1200.0);
});

it('respects the conversion window', function () {
    $result = app(FunnelEngine::class)->evaluate(signupFunnel(window: 300), $this->t->startOfDay(), $this->t->endOfDay(), TenantScope::all());

    expect(array_column($result->steps, 'count'))->toBe([4, 3, 0]);
});

it('scopes to one tenant and can exclude anonymous visitors', function () {
    $tenant = TenantScope::tenant(new TenantRef('App\\Models\\Team', '2'));
    $result = app(FunnelEngine::class)->evaluate(signupFunnel(), $this->t->startOfDay(), $this->t->endOfDay(), $tenant);

    expect(array_column($result->steps, 'count'))->toBe([1, 1, 1]);

    $known = app(FunnelEngine::class)->evaluate(signupFunnel(), $this->t->startOfDay(), $this->t->endOfDay(), TenantScope::all(), includeAnonymous: false);

    expect($known->entered)->toBe(3);
});

it('is evaluable historically for any range', function () {
    $result = app(FunnelEngine::class)->evaluate(signupFunnel(), $this->t->addDay()->startOfDay(), $this->t->addDay()->endOfDay(), TenantScope::all());

    expect($result->entered)->toBe(0)->and($result->medianSeconds)->toBeNull();
});

it('validates definitions', function () {
    FunnelDefinition::fromArray(['definition' => ['steps' => [['event' => 'only.one']], 'window_seconds' => 60]]);
})->throws(InvalidArgumentException::class);
