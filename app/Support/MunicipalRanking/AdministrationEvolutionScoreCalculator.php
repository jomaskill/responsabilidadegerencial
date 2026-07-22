<?php

namespace App\Support\MunicipalRanking;

class AdministrationEvolutionScoreCalculator
{
    /**
     * @param  array<string, float>  $weights
     * @param  array<string, array<string, mixed>>  $baselineObservations
     * @param  array<string, array<string, mixed>>  $endObservations
     * @param  array<string, array<int, float>>  $baselinePercentiles
     * @param  array<string, array<int, float>>  $endPercentiles
     * @return array{
     *     evolution_score: float|null,
     *     coverage_percent: float,
     *     status: string,
     *     evolution_summary: array{improved: int, declined: int, unchanged: int, not_comparable: int},
     *     components: array<int, array<string, mixed>>
     * }
     */
    public function calculate(
        int $municipalityId,
        array $weights,
        array $baselineObservations,
        array $endObservations,
        array $baselinePercentiles,
        array $endPercentiles,
        float $minimumCoverage,
        bool $includeComponents = true,
    ): array {
        $availableWeight = 0.0;

        foreach ($weights as $slug => $weight) {
            if (
                isset(
                    $baselineObservations[$slug],
                    $endObservations[$slug],
                    $baselinePercentiles[$slug][$municipalityId],
                    $endPercentiles[$slug][$municipalityId],
                )
            ) {
                $availableWeight += $weight;
            }
        }

        $coverage = round($availableWeight, 8);
        $eligible = $availableWeight > 0 && ($availableWeight / 100) >= $minimumCoverage;
        $score = 0.0;
        $components = [];
        $evolutionSummary = [
            'improved' => 0,
            'declined' => 0,
            'unchanged' => 0,
            'not_comparable' => 0,
        ];

        foreach ($weights as $slug => $weight) {
            $baseline = $baselineObservations[$slug] ?? null;
            $end = $endObservations[$slug] ?? null;
            $baselinePercentile = $baselinePercentiles[$slug][$municipalityId] ?? null;
            $endPercentile = $endPercentiles[$slug][$municipalityId] ?? null;
            $available = $baseline !== null
                && $end !== null
                && $baselinePercentile !== null
                && $endPercentile !== null;
            $effectiveWeight = $eligible && $available
                ? round(($weight / $availableWeight) * 100, 8)
                : 0.0;
            $percentileChange = $available
                ? round($endPercentile - $baselinePercentile, 8)
                : null;
            $contribution = $percentileChange !== null
                ? round($percentileChange * ($effectiveWeight / 100), 8)
                : null;

            if ($percentileChange === null) {
                $evolutionSummary['not_comparable']++;
            } elseif ($percentileChange > 0) {
                $evolutionSummary['improved']++;
            } elseif ($percentileChange < 0) {
                $evolutionSummary['declined']++;
            } else {
                $evolutionSummary['unchanged']++;
            }

            if ($eligible && $contribution !== null) {
                $score += $contribution;
            }

            if ($includeComponents) {
                $components[] = [
                    'indicator' => $slug,
                    'available' => $available,
                    'baseline' => [
                        'raw_value' => $baseline['value'] ?? null,
                        'effective_year' => $baseline['reference_year'] ?? null,
                        'percentile' => $baselinePercentile,
                        'source' => $baseline['source'] ?? null,
                        'release' => $baseline['release'] ?? null,
                    ],
                    'end' => [
                        'raw_value' => $end['value'] ?? null,
                        'effective_year' => $end['reference_year'] ?? null,
                        'percentile' => $endPercentile,
                        'source' => $end['source'] ?? null,
                        'release' => $end['release'] ?? null,
                    ],
                    'percentile_change' => $percentileChange,
                    'requested_weight' => round($weight, 8),
                    'effective_weight' => $effectiveWeight,
                    'contribution' => $contribution,
                    'missing_reason' => $available ? null : 'missing_from_one_or_both_endpoints',
                ];
            }
        }

        return [
            'evolution_score' => $eligible ? round($score, 6) : null,
            'coverage_percent' => $coverage,
            'status' => $eligible ? 'ranked' : 'insufficient_data',
            'evolution_summary' => $evolutionSummary,
            'components' => $components,
        ];
    }
}
