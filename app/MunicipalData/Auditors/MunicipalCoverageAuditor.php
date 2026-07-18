<?php

namespace App\MunicipalData\Auditors;

use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use App\Models\Indicator;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\ObservationFlag;
use App\MunicipalData\DataQualityAuditor;
use Illuminate\Database\Eloquent\Builder;

class MunicipalCoverageAuditor implements DataQualityAuditor
{
    public function coverage(int $referenceYear, ?string $sourceSlug = null): array
    {
        $yearStart = "{$referenceYear}-01-01";
        $yearEnd = "{$referenceYear}-12-31";
        $expected = Municipality::query()
            ->where(fn (Builder $query) => $query->whereNull('installed_at')->orWhere('installed_at', '<=', $yearEnd))
            ->where(fn (Builder $query) => $query->whereNull('extinct_at')->orWhere('extinct_at', '>=', $yearStart))
            ->count();

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

    public function audit(int $referenceYear, ?string $sourceSlug = null): array
    {
        $query = IndicatorObservation::query()
            ->with('indicatorVersion.indicator')
            ->where('reference_year', $referenceYear)
            ->whereHas('sourceRelease', function (Builder $builder) use ($sourceSlug): void {
                $builder->whereNull('superseded_by_id');

                if ($sourceSlug !== null) {
                    $builder->whereHas('dataSource', fn (Builder $source) => $source->where('slug', $sourceSlug));
                }
            });
        $checked = 0;
        $warnings = 0;
        $rejected = 0;

        $query->chunkById(1000, function ($observations) use (&$checked, &$warnings, &$rejected): void {
            foreach ($observations as $observation) {
                $checked++;
                $rejected += (int) ($observation->quality_status === QualityStatus::Rejected);
                $violation = $this->rangeViolation($observation);

                if ($violation === null) {
                    continue;
                }

                ObservationFlag::query()->firstOrCreate(
                    ['indicator_observation_id' => $observation->id, 'code' => 'out_of_expected_range'],
                    ['severity' => 'warning', 'message' => $violation, 'details' => ['value' => $observation->value]],
                );
                $warnings++;
            }
        });

        return compact('checked', 'warnings', 'rejected');
    }

    private function rangeViolation(IndicatorObservation $observation): ?string
    {
        if ($observation->value === null) {
            return null;
        }

        $indicator = $observation->indicatorVersion->indicator;
        $value = (float) $observation->value;

        if ($indicator->unit === 'percentual' && ($value < 0 || $value > 100)) {
            return 'Percentage is outside the expected 0–100 range.';
        }

        if ($indicator->unit === 'indice_0_10' && ($value < 0 || $value > 10)) {
            return 'Index is outside the expected 0–10 range.';
        }

        if (in_array($indicator->unit, ['pessoas', 'obitos', 'BRL', 'BRL_por_habitante'], true) && $value < 0) {
            return 'Value cannot be negative for this indicator.';
        }

        return null;
    }
}
