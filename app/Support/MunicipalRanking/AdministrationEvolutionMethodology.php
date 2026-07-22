<?php

namespace App\Support\MunicipalRanking;

use InvalidArgumentException;

class AdministrationEvolutionMethodology
{
    /** @return array{baseline_year: int, end_year: int} */
    public function endpoints(int $electionYear): array
    {
        return match ($electionYear) {
            2016 => ['baseline_year' => 2017, 'end_year' => 2020],
            2020 => ['baseline_year' => 2021, 'end_year' => 2024],
            2024 => ['baseline_year' => 2024, 'end_year' => 2025],
            default => throw new InvalidArgumentException('Unsupported municipal election year.'),
        };
    }

    /**
     * @param  array<string, int>  $baselineEffectiveYears
     * @param  array<string, int>  $endEffectiveYears
     * @return array<int, string>
     */
    public function advancedIndicators(array $baselineEffectiveYears, array $endEffectiveYears): array
    {
        $advanced = [];

        foreach ($endEffectiveYears as $slug => $endYear) {
            $baselineYear = $baselineEffectiveYears[$slug] ?? null;

            if ($baselineYear !== null && $endYear > $baselineYear) {
                $advanced[] = $slug;
            }
        }

        sort($advanced);

        return $advanced;
    }

    /**
     * @param  array<string, float>  $endProfileWeights
     * @param  array<int, string>  $advancedIndicators
     */
    public function globalCoverage(array $endProfileWeights, array $advancedIndicators): float
    {
        return round(array_sum(array_intersect_key(
            $endProfileWeights,
            array_fill_keys($advancedIndicators, true),
        )), 8);
    }

    /**
     * @param  array<int, string>  $advancedIndicators
     * @param  array<string, array<int, array<int, string>>>|null  $themes
     */
    public function globalCoverageForConfiguredProfile(
        array $advancedIndicators,
        ?array $themes = null,
    ): float {
        $themes ??= (array) config('municipal_ranking.themes', []);

        if ($themes === []) {
            return 0.0;
        }

        $themeWeight = 100 / count($themes);
        $coverage = 0.0;

        foreach ($themes as $dimensions) {
            $dimensions = array_values((array) $dimensions);

            if ($dimensions === []) {
                continue;
            }

            $dimensionWeight = $themeWeight / count($dimensions);

            foreach ($dimensions as $candidates) {
                if (array_intersect((array) $candidates, $advancedIndicators) !== []) {
                    $coverage += $dimensionWeight;
                }
            }
        }

        return round($coverage, 8);
    }

    public function canPublishRanking(float $globalCoverage, float $minimumCoverage = 0.60): bool
    {
        return ($globalCoverage / 100) >= $minimumCoverage;
    }
}
