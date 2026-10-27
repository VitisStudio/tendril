<?php

namespace VitisStudio\Tendril\Product\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * One row per (tenant, actor, date, kind, subject). Grouped queries select a
 * synthetic string "id" so Filament tables have a stable record key.
 */
class ActorActivity extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::ACTIVITY;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
