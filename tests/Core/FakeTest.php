<?php

use VitisStudio\Tendril\Core\Facades\Tendril;

it('offers an in-memory fake for host application tests', function () {
    $fake = Tendril::fake();

    Tendril::track('invoice.paid', ['amount' => 2500, 'currency' => 'USD']);
    Tendril::flush();

    $fake->assertTracked('invoice.paid', fn (array $event) => $event['properties']['amount'] === 2500);
    $fake->assertTracked('invoice.paid', times: 1);
    $fake->assertNotTracked('invoice.voided');

    expect(storedEvents())->toHaveCount(0);
});
