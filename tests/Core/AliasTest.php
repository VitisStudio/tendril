<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

function linkAlias(string $anonymous, string $actorId, CarbonImmutable $at, ?CarbonImmutable $since = null, string $action = 'link'): void
{
    app(BatchWriter::class)->write(Batch::make(aliases: [[
        'action' => $action,
        'anonymous_id' => $anonymous,
        'actor_type' => 'App\\Models\\User',
        'actor_id' => $actorId,
        'at' => Instant::serialize($at),
        'since' => $since !== null ? Instant::serialize($since) : null,
        'reason' => 'test',
    ]]));
}

beforeEach(function () {
    $this->t0 = CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC');
});

it('covers the anonymous history on first link', function () {
    writeEvent('page.viewed', $this->t0, ['anonymous_id' => 'anon-1']);
    linkAlias('anon-1', '1', $this->t0->addHour());

    $resolver = AliasResolver::make();

    expect($resolver->resolve('anon-1', $this->t0)?->id)->toBe('1')
        ->and($resolver->resolve('anon-1', $this->t0->addDays(3))?->id)->toBe('1')
        ->and($resolver->resolve('anon-1', $this->t0->subSecond()))->toBeNull();
});

it('never merges two actors: a different actor opens a new range', function () {
    writeEvent('page.viewed', $this->t0, ['anonymous_id' => 'kiosk']);
    linkAlias('kiosk', '1', $this->t0->addHour());
    linkAlias('kiosk', '2', $this->t0->addHours(5));

    $rows = db()->table(Tables::ALIASES)->orderBy('valid_from')->get();
    $resolver = AliasResolver::make();

    expect($rows)->toHaveCount(2)
        ->and(Instant::parse($rows[0]->valid_to)->equalTo($this->t0->addHours(5)))->toBeTrue()
        ->and($rows[1]->valid_to)->toBeNull()
        ->and($resolver->resolve('kiosk', $this->t0->addHours(2))?->id)->toBe('1')
        ->and($resolver->resolve('kiosk', $this->t0->addHours(6))?->id)->toBe('2')
        ->and($resolver->isDeterminate('kiosk', $this->t0->addHours(2), $this->t0->addHours(6)))->toBeFalse()
        ->and($resolver->isDeterminate('kiosk', $this->t0->addHours(6), $this->t0->addHours(7)))->toBeTrue();
});

it('is idempotent for the same actor and starts at the link instant after a closed range', function () {
    writeEvent('page.viewed', $this->t0, ['anonymous_id' => 'anon-2']);
    linkAlias('anon-2', '1', $this->t0->addHour());
    linkAlias('anon-2', '1', $this->t0->addHours(2));

    expect(db()->table(Tables::ALIASES)->count())->toBe(1);

    linkAlias('anon-2', '1', $this->t0->addHours(3), action: 'close');
    linkAlias('anon-2', '1', $this->t0->addHours(10));

    $resolver = AliasResolver::make();

    expect(db()->table(Tables::ALIASES)->count())->toBe(2)
        ->and($resolver->resolve('anon-2', $this->t0->addHours(5)))->toBeNull()
        ->and($resolver->resolve('anon-2', $this->t0->addHours(11))?->id)->toBe('1');
});

it('keeps unaliased visitors anonymous forever', function () {
    $resolver = AliasResolver::make();

    expect($resolver->subject(null, null, 'stranger', $this->t0))->toEqual(ActorRef::anonymous('stranger'))
        ->and($resolver->subject('App\\Models\\User', '5', 'stranger', $this->t0)?->id)->toBe('5')
        ->and($resolver->subject(null, null, null, $this->t0))->toBeNull();
});

it('links explicitly through identify() and alias()', function () {
    Tendril::alias('anon-3', new ActorRef('App\\Models\\User', '3'));
    Tendril::flush();

    expect(db()->table(Tables::ALIASES)->where('anonymous_id', 'anon-3')->value('actor_id'))->toBe('3');
});

it('reopens derived buckets for the visitor history when a link is created', function () {
    writeEvent('page.viewed', $this->t0, ['anonymous_id' => 'anon-4']);
    db()->table(Tables::DIRTY_BUCKETS)->delete();

    linkAlias('anon-4', '4', $this->t0->addDay());

    expect(db()->table(Tables::DIRTY_BUCKETS)->count())->toBe(1);
});
