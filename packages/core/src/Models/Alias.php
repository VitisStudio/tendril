<?php

namespace VitisStudio\Tendril\Core\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

class Alias extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::ALIASES;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
