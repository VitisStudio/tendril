<?php

return [

    'attribution' => [
        // first_touch | last_touch | linear | position_based
        'default_model' => 'last_touch',
        'lookback_days' => 90,
        // Position-based weights for first and last touch; the rest is shared by the middle.
        'position_weights' => ['first' => 0.4, 'last' => 0.4],
    ],

    // Currency used when a conversion defines neither a fixed nor a property currency.
    'default_currency' => env('TENDRIL_DEFAULT_CURRENCY', 'USD'),

    // ISO 4217 minor-unit exponents that differ from 2.
    'currency_exponents' => [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ],
];
