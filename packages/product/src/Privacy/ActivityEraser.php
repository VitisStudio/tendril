<?php

namespace VitisStudio\Tendril\Product\Privacy;

use VitisStudio\Tendril\Core\Contracts\SubjectEraser;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Removes the subject's activity rows immediately (including rows for days
 * whose raw events were already pruned and therefore cannot be rebuilt).
 * Retained days are recomputed from the pseudonymized events.
 */
final class ActivityEraser implements SubjectEraser
{
    public function __construct(private readonly Database $db) {}

    public function erase(?ActorRef $actor, array $anonymousIds): int
    {
        $deleted = 0;

        if ($actor !== null) {
            $deleted += $this->db->table(Tables::ACTIVITY)
                ->where('actor_type', $actor->type)
                ->where('actor_id', $actor->id)
                ->delete();
        }

        foreach (array_chunk($anonymousIds, 500) as $ids) {
            $deleted += $this->db->table(Tables::ACTIVITY)
                ->where('actor_type', ActorRef::ANONYMOUS)
                ->whereIn('actor_id', $ids)
                ->delete();
        }

        return $deleted;
    }
}
