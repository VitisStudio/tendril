<?php

namespace VitisStudio\Tendril\Core\Privacy;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Storage\Audit;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Entry point for privacy erasure: records an audited request with fresh,
 * unlinkable pseudonyms and queues the chunked EraseSubject job.
 */
final class ErasureManager
{
    public function __construct(
        private readonly Database $db,
        private readonly Audit $audit,
    ) {}

    public function eraseActor(ActorRef $actor, ?string $performedBy = null): string
    {
        return $this->request($actor, null, $performedBy);
    }

    public function eraseAnonymous(string $anonymousId, ?string $performedBy = null): string
    {
        return $this->request(null, $anonymousId, $performedBy);
    }

    private function request(?ActorRef $actor, ?string $anonymousId, ?string $performedBy): string
    {
        if ($actor === null && ($anonymousId === null || $anonymousId === '')) {
            throw new InvalidArgumentException('Erasure needs an actor or an anonymous id.');
        }

        $id = (string) Str::ulid();
        $fingerprint = Audit::fingerprint($actor?->key() ?? 'anon|'.$anonymousId);

        $this->db->table(Tables::ERASURES)->insert([
            'id' => $id,
            'subject_type' => $actor !== null ? 'actor' : 'anonymous',
            'subject_fingerprint' => $fingerprint,
            'actor_pseudonym' => 'erased:'.strtolower((string) Str::ulid()),
            'anonymous_pseudonym' => 'erased:'.strtolower((string) Str::ulid()),
            'status' => 'pending',
            'counts' => json_encode([]) ?: '[]',
            'performed_by' => $performedBy,
            'requested_at' => $this->db->time(Instant::now()),
            'completed_at' => null,
        ]);

        $this->audit->record('erasure.requested', [
            'erasure_id' => $id,
            'subject_type' => $actor !== null ? 'actor' : 'anonymous',
            'subject_fingerprint' => $fingerprint,
        ], $performedBy);

        app(Dispatcher::class)->dispatch(new EraseSubject($id, $actor?->type, $actor?->id, $anonymousId));

        return $id;
    }
}
