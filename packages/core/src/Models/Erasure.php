<?php

namespace VitisStudio\Tendril\Core\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

class Erasure extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::ERASURES;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['actor_pseudonym', 'anonymous_pseudonym'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'requested_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
