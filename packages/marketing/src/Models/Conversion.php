<?php

namespace VitisStudio\Tendril\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $definition
 * @property bool $active
 */
class Conversion extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::CONVERSIONS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'active' => 'boolean',
            'revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->isDirty('definition')) {
                $model->setAttribute('revision', (int) $model->getOriginal('revision') + 1);
            }
        });
    }
}
