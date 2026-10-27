<?php

namespace VitisStudio\Tendril\Core\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

class Session extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::SESSIONS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'click_ids' => 'array',
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'event_count' => 'integer',
            'page_view_count' => 'integer',
        ];
    }
}
