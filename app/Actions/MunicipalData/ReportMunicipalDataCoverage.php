<?php

namespace App\Actions\MunicipalData;

use App\Enums\AvailabilityStatus;
use App\Models\Indicator;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Builder;

class ReportMunicipalDataCoverage
{
    /**
     * @return array<int, array{
     *     indicator: string,
     *     theme: string,
     *     expected: int,
     *     available: int,
     *     missing: int,
     *     coverage_percent: float
     * }>
     */
    public function execute(int $referenceYear, ?string $sourceSlug = null): array
    {
        $expected = Municipality::query()->existingInYear($referenceYear)->count();

        return Indicator::query()
            ->where('is_active', true)
            ->orderBy('theme')
            ->orderBy('slug')
            ->get()
            ->map(function (Indicator $indicator) use ($referenceYear, $sourceSlug, $expected): array {
                $query = IndicatorObservation::query()
                    ->where('reference_year', $referenceYear)
                    ->whereIn('availability_status', [AvailabilityStatus::Available->value, AvailabilityStatus::Provisional->value])
                    ->whereHas('indicatorVersion', fn (Builder $builder) => $builder->where('indicator_id', $indicator->id))
                    ->whereHas('sourceRelease', function (Builder $builder) use ($sourceSlug): void {
                        $builder->whereNull('superseded_by_id');

                        if ($sourceSlug !== null) {
                            $builder->whereHas('dataSource', fn (Builder $source) => $source->where('slug', $sourceSlug));
                        }
                    });
                $available = $query->distinct()->count('municipality_id');

                return [
                    'indicator' => $indicator->slug,
                    'theme' => $indicator->theme,
                    'expected' => $expected,
                    'available' => $available,
                    'missing' => max(0, $expected - $available),
                    'coverage_percent' => $expected === 0 ? 0.0 : round(($available / $expected) * 100, 2),
                ];
            })
            ->all();
    }
}
