<?php

namespace VitisStudio\Tendril\Core\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Read model for raw events. Events are append-only: saving through
 * Eloquent is refused; erasure uses its own audited write path.
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed>|null $properties
 * @property array<string, mixed>|null $context
 */
class Event extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::EVENTS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new RuntimeException('Tendril events are append-only facts.'));
        static::deleting(fn () => throw new RuntimeException('Tendril events are append-only facts.'));
    }
}
