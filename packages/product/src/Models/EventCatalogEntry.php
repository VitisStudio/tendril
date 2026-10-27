<?php

namespace VitisStudio\Tendril\Product\Models;

use Illuminate\Database\Eloquent\Model;
use VitisStudio\Tendril\Core\Models\Concerns\UsesTendrilConnection;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Read model over tendril_rollups for grouped catalog queries: the event
 * name is selected as a string "id" (the table's integer key is not used).
 */
class EventCatalogEntry extends Model
{
    use UsesTendrilConnection;

    protected $table = Tables::ROLLUPS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
