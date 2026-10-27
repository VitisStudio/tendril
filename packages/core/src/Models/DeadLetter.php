<?php

namespace VitisStudio\Tendril\Core\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

class DeadLetter extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::DEAD_LETTERS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'failed_at' => 'immutable_datetime',
            'events' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
