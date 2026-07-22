<?php

use App\Enums\IndicatorDirection;
use App\Support\MunicipalRanking\MunicipalityScoreCalculator;
use App\Support\MunicipalRanking\PercentileNormalizer;

test('percentiles preserve ties and invert lower-is-better indicators', function () {
    $normalizer = new PercentileNormalizer;
    $values = [1 => 10.0, 2 => 20.0, 3 => 20.0, 4 => 40.0];

    expect($normalizer->normalize($values, IndicatorDirection::HigherIsBetter))
        ->toBe([4 => 100.0, 2 => 50.0, 3 => 50.0, 1 => 0.0])
        ->and($normalizer->normalize($values, IndicatorDirection::LowerIsBetter))
        ->toBe([1 => 100.0, 2 => 50.0, 3 => 50.0, 4 => 0.0]);
});

test('missing weights are redistributed only after minimum coverage', function () {
    $calculator = new MunicipalityScoreCalculator;
    $normalized = ['available_indicator' => [10 => 80.0]];
    $observation = [
        'available_indicator' => [
            'value' => 12.0,
            'unit' => 'percentual',
            'reference_year' => 2024,
            'direction' => IndicatorDirection::HigherIsBetter->value,
        ],
    ];

    $eligible = $calculator->calculate(
        10,
        ['available_indicator' => 60.0, 'missing_indicator' => 40.0],
        $observation,
        $normalized,
        0.60,
    );
    $ineligible = $calculator->calculate(
        10,
        ['available_indicator' => 59.0, 'missing_indicator' => 41.0],
        $observation,
        $normalized,
        0.60,
    );

    expect($eligible['status'])->toBe('ranked')
        ->and($eligible['score'])->toBe(80.0)
        ->and($eligible['coverage_percent'])->toBe(60.0)
        ->and($eligible['components'][0]['effective_weight'])->toBe(100.0)
        ->and($ineligible['status'])->toBe('insufficient_data')
        ->and($ineligible['score'])->toBeNull();
});
