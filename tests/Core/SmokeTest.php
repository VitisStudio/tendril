<?php

use Illuminate\Support\Facades\Schema;
use VitisStudio\Tendril\Core\Support\Tables;

it('boots the workbench against the configured database', function () {
    expect(db()->connection()->select('select 1 as one')[0]->one)->toEqual(1);

    foreach (Tables::core() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is missing");
    }

    expect(Schema::hasTable(Tables::ROLLUPS))->toBeTrue()
        ->and(Schema::hasTable(Tables::ACTIVITY))->toBeTrue()
        ->and(Schema::hasTable(Tables::CONVERSIONS))->toBeTrue()
        ->and(Schema::hasTable(Tables::DELIVERIES))->toBeTrue();
});

it('serves workbench routes', function () {
    $this->get('/')->assertOk()->assertSee('Home');
});
