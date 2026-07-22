<?php

return [
    'methodology_version' => '1.3.0',
    'cache_payload_version' => 9,
    'minimum_year' => 2017,
    'maximum_year' => 2025,
    'minimum_coverage' => 0.60,
    'cache' => [
        'fresh_seconds' => 600,
        'stale_seconds' => 1800,
    ],
    'themes' => [
        'economia' => [
            ['gdp_per_capita'],
        ],
        'educacao' => [
            ['ideb_initial_years'],
            ['ideb_final_years'],
            ['literacy_rate'],
        ],
        'saneamento' => [
            ['water_service_coverage', 'water_census'],
            ['sewer_service_coverage', 'sewer_census'],
        ],
        'seguranca' => [
            ['homicide_rate_rolling_3y', 'homicide_rate'],
        ],
    ],
    'custom_weight_indicators' => [
        'gdp_per_capita',
        'gdp_real_growth',
        'ideb_initial_years',
        'ideb_final_years',
        'literacy_rate',
        'water_census',
        'sewer_census',
        'water_service_coverage',
        'sewer_service_coverage',
        'homicide_rate',
        'homicide_rate_rolling_3y',
    ],
    'context_indicators' => [
        'population',
        'gdp_nominal',
        'homicide_count',
    ],
];
