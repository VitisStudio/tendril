<?php

namespace VitisStudio\Tendril\Product\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property array<string, mixed> $definition
 * @property bool $active
 */
class Cohort extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::COHORTS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'active' => 'boolean',
            'revision' => 'integer',
        ];
    }
}
