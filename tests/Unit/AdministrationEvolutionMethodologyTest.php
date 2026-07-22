<?php

use App\Support\MunicipalRanking\AdministrationEvolutionMethodology;
use App\Support\MunicipalRanking\AdministrationEvolutionScoreCalculator;

test('evolution methodology only uses indicators whose effective year advanced', function () {
    $methodology = new AdministrationEvolutionMethodology;

    expect($methodology->endpoints(2016))
        ->toBe(['baseline_year' => 2017, 'end_year' => 2020])
        ->and($methodology->endpoints(2020))
        ->toBe(['baseline_year' => 2021, 'end_year' => 2024])
        ->and($methodology->advancedIndicators(
            ['gdp_per_capita' => 2021, 'water_census' => 2022],
            ['gdp_per_capita' => 2023, 'water_census' => 2022, 'homicide_rate' => 2024],
        ))
        ->toBe(['gdp_per_capita'])
        ->and($methodology->globalCoverage(
            ['gdp_per_capita' => 25.0, 'water_census' => 25.0, 'homicide_rate' => 50.0],
            ['gdp_per_capita', 'homicide_rate'],
        ))
        ->toBe(75.0)
        ->and($methodology->globalCoverageForConfiguredProfile([
            'gdp_per_capita',
            'ideb_initial_years',
            'ideb_final_years',
            'homicide_rate',
        ], [
            'economia' => [['gdp_per_capita']],
            'educacao' => [['ideb_initial_years'], ['ideb_final_years'], ['literacy_rate']],
            'saneamento' => [['water_service_coverage'], ['sewer_service_coverage']],
            'seguranca' => [['homicide_rate_rolling_3y', 'homicide_rate']],
        ]))
        ->toBe(66.66666667)
        ->and($methodology->canPublishRanking(59.99))->toBeFalse()
        ->and($methodology->canPublishRanking(60.0))->toBeTrue();
});

test('evolution score redistributes weights after the municipal coverage threshold', function () {
    $calculator = new AdministrationEvolutionScoreCalculator;
    $result = $calculator->calculate(
        municipalityId: 10,
        weights: ['economy' => 60.0, 'education' => 40.0],
        baselineObservations: ['economy' => ['value' => 100, 'reference_year' => 2021]],
        endObservations: ['economy' => ['value' => 120, 'reference_year' => 2024]],
        baselinePercentiles: ['economy' => [10 => 25.0]],
        endPercentiles: ['economy' => [10 => 75.0]],
        minimumCoverage: 0.60,
    );

    expect($result['status'])->toBe('ranked')
        ->and($result['coverage_percent'])->toBe(60.0)
        ->and($result['evolution_score'])->toBe(50.0)
        ->and($result['components'][0]['effective_weight'])->toBe(100.0)
        ->and($result['components'][0]['percentile_change'])->toBe(50.0);
});
