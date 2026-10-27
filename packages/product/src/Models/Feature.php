<?php

namespace VitisStudio\Tendril\Product\Models;

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
 * @property int $revision
 */
class Feature extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::FEATURES;

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
                $model->revision = (int) $model->getOriginal('revision') + 1;
            }
        });
    }
}
