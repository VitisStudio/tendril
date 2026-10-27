<?php

namespace VitisStudio\Tendril\Core\Storage;

use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Idempotent multi-row event insert. Each attempt runs in its own
 * transaction/savepoint so a failed chunk can be bisected safely on
 * PostgreSQL (where an error aborts the enclosing transaction).
 */
class RowInserter
{
    public function __construct(protected readonly Database $db) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int rows actually inserted (duplicates are ignored)
     */
    public function insert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $connection = $this->db->connection();

        return (int) $connection->transaction(
            fn () => $connection->table(Tables::EVENTS)->insertOrIgnore($rows),
        );
    }
}
