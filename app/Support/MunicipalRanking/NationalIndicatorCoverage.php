<?php

namespace App\Support\MunicipalRanking;

use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use App\Models\IndicatorObservation;
use App\Models\Municipality;

class NationalIndicatorCoverage
{
    /** @var array<string, array<string, int>> */
    private array $memoizedEffectiveYears = [];

    /**
     * @param  array<int, string>  $indicatorSlugs
     * @return array<string, int>
     */
    public function completeEffectiveYears(int $selectedYear, array $indicatorSlugs): array
    {
        $indicatorSlugs = array_values(array_unique($indicatorSlugs));
        sort($indicatorSlugs);

        if ($indicatorSlugs === []) {
            return [];
        }

        $memoKey = $selectedYear.':'.implode(',', $indicatorSlugs);

        return $this->memoizedEffectiveYears[$memoKey] ??= $this->resolveCompleteEffectiveYears(
            $selectedYear,
            $indicatorSlugs,
        );
    }

    /**
     * @param  array<int, string>  $indicatorSlugs
     * @return array<string, int>
     */
    private function resolveCompleteEffectiveYears(int $selectedYear, array $indicatorSlugs): array
    {
        $coverageByYear = IndicatorObservation::query()
            ->join('indicator_versions', 'indicator_versions.id', '=', 'indicator_observations.indicator_version_id')
            ->join('indicators', 'indicators.id', '=', 'indicator_versions.indicator_id')
            ->join('source_releases', 'source_releases.id', '=', 'indicator_observations.source_release_id')
            ->whereIn('indicators.slug', $indicatorSlugs)
            ->where('indicator_observations.reference_year', '<=', $selectedYear)
            ->whereNull('source_releases.superseded_by_id')
            ->where('indicator_observations.quality_status', QualityStatus::Accepted->value)
            ->whereIn('indicator_observations.availability_status', [
                AvailabilityStatus::Available->value,
                AvailabilityStatus::Provisional->value,
            ])
            ->groupBy('indicators.slug', 'indicator_observations.reference_year')
            ->orderByDesc('indicator_observations.reference_year')
            ->selectRaw(
                'indicators.slug, indicator_observations.reference_year, '
                .'COUNT(DISTINCT indicator_observations.municipality_id) AS available_municipalities',
            )
            ->get()
            ->map(function (IndicatorObservation $observation): array {
                return [
                    'slug' => (string) $observation->getAttribute('slug'),
                    'reference_year' => $observation->reference_year,
                    'available_municipalities' => (int) $observation->getAttribute('available_municipalities'),
                ];
            });
        $expectedMunicipalities = [];
        $completeYears = [];

        foreach ($coverageByYear as $coverage) {
            $slug = $coverage['slug'];
            $referenceYear = $coverage['reference_year'];

            if (isset($completeYears[$slug])) {
                continue;
            }

            $expectedMunicipalities[$referenceYear] ??= Municipality::query()
                ->existingInYear($referenceYear)
                ->count();

            if (
                $expectedMunicipalities[$referenceYear] > 0
                && $coverage['available_municipalities'] === $expectedMunicipalities[$referenceYear]
            ) {
                $completeYears[$slug] = $referenceYear;
            }
        }

        ksort($completeYears);

        return $completeYears;
    }
}
