<?php

use Illuminate\Http\Request;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\Decision;
use VitisStudio\Tendril\Core\Decisions\DecisionPipeline;
use VitisStudio\Tendril\Core\Decisions\Recognizers\DropBots;
use VitisStudio\Tendril\Core\Decisions\Recognizers\DropEnvironments;
use VitisStudio\Tendril\Core\Decisions\Recognizers\DropHealthChecks;
use VitisStudio\Tendril\Core\Decisions\Recognizers\DropIpRanges;
use VitisStudio\Tendril\Core\Decisions\Recognizers\DropRoutes;
use VitisStudio\Tendril\Core\Decisions\Recognizers\SampleEvents;
use VitisStudio\Tendril\Core\Decisions\Recognizers\TagActors;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Identity\ActorRef;

function analyticsContext(array $overrides = []): AnalyticsContext
{
    return new AnalyticsContext(
        name: $overrides['name'] ?? 'page.viewed',
        source: 'http',
        environment: $overrides['environment'] ?? 'production',
        properties: ['plan' => 'pro', 'secret_note' => 'x'],
        context: ['route' => $overrides['route'] ?? 'home'],
        actor: $overrides['actor'] ?? null,
        tenant: null,
        anonymousId: $overrides['anonymous'] ?? 'anon-1',
        request: $overrides['request'] ?? null,
    );
}

it('lets the host track, drop, sample, redact and tag', function () {
    $pipeline = new DecisionPipeline([
        new class implements VitisStudio\Tendril\Core\Contracts\TrackingDecision
        {
            public function decide(AnalyticsContext $context): Decision
            {
                return Decision::redact(['plan']);
            }
        },
        new TagActors(fn (AnalyticsContext $c) => $c->actor?->id === '1', 'internal'),
    ]);

    $outcome = $pipeline->evaluate(analyticsContext(['actor' => new ActorRef('User', '1')]));
    $event = $outcome->apply(['properties' => ['plan' => 'pro', 'keep' => 1], 'context' => []]);

    expect($outcome->keep)->toBeTrue()
        ->and($event['properties'])->toBe(['keep' => 1])
        ->and($event['context']['tags'])->toBe(['internal']);

    $drop = (new DecisionPipeline([new DropRoutes(['admin.*'])]))->evaluate(analyticsContext(['route' => 'admin.users']));
    expect($drop->keep)->toBeFalse()->and($drop->reason)->toBe('route');
});

it('samples deterministically per actor/visitor', function () {
    $pipeline = new DecisionPipeline([new SampleEvents(['page.*' => 0.5])]);
    $kept = 0;

    for ($i = 0; $i < 400; $i++) {
        $first = $pipeline->evaluate(analyticsContext(['anonymous' => "visitor-{$i}"]))->keep;
        $second = $pipeline->evaluate(analyticsContext(['anonymous' => "visitor-{$i}"]))->keep;
        expect($first)->toBe($second);
        $kept += $first ? 1 : 0;
    }

    expect($kept)->toBeGreaterThan(140)->toBeLessThan(260);

    $outcome = $pipeline->evaluate(analyticsContext(['anonymous' => 'visitor-keep-me']));

    if ($outcome->keep) {
        expect($outcome->apply(['properties' => [], 'context' => []])['context']['sample_rate'])->toBe(0.5);
    }
});

it('ships opt-in recognizers for bots, health checks, environments and IP ranges', function () {
    $bot = Request::create('/pricing', 'GET', server: ['HTTP_USER_AGENT' => 'Googlebot/2.1']);
    $human = Request::create('/pricing', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh)', 'REMOTE_ADDR' => '10.1.2.3']);
    $health = Request::create('/healthz', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0']);

    expect((new DropBots)->decide(analyticsContext(['request' => $bot]))->type)->toBe(Decision::DROP)
        ->and((new DropBots)->decide(analyticsContext(['request' => $human]))->type)->toBe(Decision::TRACK)
        ->and((new DropHealthChecks)->decide(analyticsContext(['request' => $health]))->type)->toBe(Decision::DROP)
        ->and((new DropEnvironments(['staging']))->decide(analyticsContext(['environment' => 'staging']))->type)->toBe(Decision::DROP)
        ->and((new DropIpRanges(['10.0.0.0/8']))->decide(analyticsContext(['request' => $human]))->type)->toBe(Decision::DROP);
});

it('applies configured decisions during capture', function () {
    config(['tendril.decisions' => [[DropRoutes::class, ['routes' => ['pricing']]]]]);

    $this->get('/pricing')->assertOk();
    $this->get('/')->assertOk();

    expect(storedEvents('page.viewed')->pluck('context')->pluck('route')->all())->toBe(['home']);
});

it('accepts runtime decisions through the facade', function () {
    Tendril::decideUsing(new class implements VitisStudio\Tendril\Core\Contracts\TrackingDecision
    {
        public function decide(AnalyticsContext $context): Decision
        {
            return $context->name === 'noise.event' ? Decision::drop('noise') : Decision::track();
        }
    });

    Tendril::track('noise.event');
    Tendril::track('signal.event');
    Tendril::flush();

    expect(storedEvents()->pluck('name')->all())->toBe(['signal.event']);
});
