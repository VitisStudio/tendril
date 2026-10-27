<?php

namespace VitisStudio\Tendril\Core\Storage;

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Append-only audit trail for identity merges and erasure.
 */
final class Audit
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param  array<string, mixed>  $subject
     */
    public function record(string $action, array $subject, ?string $performedBy = null): void
    {
        $this->db->table(Tables::AUDIT)->insert([
            'id' => (string) Str::ulid(),
            'action' => substr($action, 0, 64),
            'subject' => json_encode($subject, JSON_UNESCAPED_SLASHES) ?: '{}',
            'performed_by' => $performedBy !== null ? substr($performedBy, 0, 191) : null,
            'created_at' => $this->db->time(Instant::now()),
        ]);
    }

    /**
     * Keyed hash used where the audit must prove "this subject" without storing its id.
     */
    public static function fingerprint(string $value): string
    {
        $key = (string) config('app.key', '');

        return hash_hmac('sha256', $value, $key !== '' ? $key : 'tendril');
    }
}
