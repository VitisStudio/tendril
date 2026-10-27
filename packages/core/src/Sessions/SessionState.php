<?php

namespace VitisStudio\Tendril\Core\Sessions;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Analytics session state kept alongside (not as) the Laravel session. It is
 * re-written after every request so it survives regenerate() and invalidate().
 */
final class SessionState
{
    public function __construct(
        public readonly string $id,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $lastSeenAt,
        public readonly string $anonymousId,
        public readonly CarbonImmutable $anonymousSince,
        public readonly ?string $campaignFingerprint = null,
    ) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ($data['v'] ?? null) !== 1) {
            return null;
        }

        $id = $data['id'] ?? null;
        $anon = $data['anon'] ?? null;
        $started = Instant::tryParse($data['started'] ?? null);
        $seen = Instant::tryParse($data['seen'] ?? null);
        $since = Instant::tryParse($data['anon_since'] ?? null);

        if (! is_string($id) || strlen($id) !== 26 || ! is_string($anon) || $anon === '' || $started === null || $seen === null) {
            return null;
        }

        return new self($id, $started, $seen, $anon, $since ?? $started, is_string($data['fp'] ?? null) ? $data['fp'] : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => 1,
            'id' => $this->id,
            'started' => Instant::serialize($this->startedAt),
            'seen' => Instant::serialize($this->lastSeenAt),
            'anon' => $this->anonymousId,
            'anon_since' => Instant::serialize($this->anonymousSince),
            'fp' => $this->campaignFingerprint,
        ];
    }

    public function touched(CarbonImmutable $at, ?string $anonymousId = null): self
    {
        return new self(
            $this->id,
            $this->startedAt,
            $at->greaterThan($this->lastSeenAt) ? $at : $this->lastSeenAt,
            $anonymousId ?? $this->anonymousId,
            $this->anonymousSince,
            $this->campaignFingerprint,
        );
    }

    public function expiredAt(CarbonImmutable $now, int $inactivityMinutes, int $hardCapMinutes): bool
    {
        return $now->greaterThan($this->lastSeenAt->addMinutes($inactivityMinutes))
            || $now->greaterThan($this->startedAt->addMinutes($hardCapMinutes));
    }
}
