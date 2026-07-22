<?php

namespace App\Support\MunicipalRanking;

class MunicipalityScoreCalculator
{
    /**
     * @param  array<string, float>  $weights
     * @param  array<string, array<string, mixed>>  $observations
     * @param  array<string, array<int, float>>  $normalizedScores
     * @return array{score: float|null, coverage_percent: float, status: string, components: array<int, array<string, mixed>>}
     */
    public function calculate(
        int $municipalityId,
        array $weights,
        array $observations,
        array $normalizedScores,
        float $minimumCoverage,
        bool $includeComponents = true,
    ): array {
        $availableWeight = 0.0;

        foreach ($weights as $slug => $weight) {
            if (isset($observations[$slug], $normalizedScores[$slug][$municipalityId])) {
                $availableWeight += $weight;
            }
        }

        $coverage = round($availableWeight, 8);
        $eligible = $availableWeight > 0 && ($availableWeight / 100) >= $minimumCoverage;
        $score = 0.0;
        $components = [];

        foreach ($weights as $slug => $weight) {
            $observation = $observations[$slug] ?? null;
            $normalized = $normalizedScores[$slug][$municipalityId] ?? null;
            $effectiveWeight = $eligible && $normalized !== null
                ? round(($weight / $availableWeight) * 100, 8)
                : 0.0;
            $contribution = $normalized !== null
                ? round($normalized * ($effectiveWeight / 100), 8)
                : null;

            if ($eligible && $contribution !== null) {
                $score += $contribution;
            }

            if ($includeComponents) {
                $components[] = [
                    'indicator' => $slug,
                    'available' => $observation !== null,
                    'raw_value' => $observation['value'] ?? null,
                    'unit' => $observation['unit'] ?? null,
                    'effective_year' => $observation['reference_year'] ?? null,
                    'direction' => $observation['direction'] ?? null,
                    'percentile' => $normalized,
                    'requested_weight' => round($weight, 8),
                    'effective_weight' => $effectiveWeight,
                    'contribution' => $contribution,
                    'source' => $observation['source'] ?? null,
                    'release' => $observation['release'] ?? null,
                    'missing_reason' => $observation === null ? 'missing_from_current_release' : null,
                ];
            }
        }

        return [
            'score' => $eligible ? round($score, 8) : null,
            'coverage_percent' => $coverage,
            'status' => $eligible ? 'ranked' : 'insufficient_data',
            'components' => $components,
        ];
    }
}
