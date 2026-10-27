<?php

namespace VitisStudio\Tendril\Core\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

class Touchpoint extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::TOUCHPOINTS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'click_ids' => 'array',
            'has_click_id' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
