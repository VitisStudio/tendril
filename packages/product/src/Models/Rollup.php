<?php

namespace VitisStudio\Tendril\Product\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

class Rollup extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::ROLLUPS;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bucket_start' => 'immutable_datetime',
            'count' => 'integer',
            'sum' => 'integer',
        ];
    }
}
