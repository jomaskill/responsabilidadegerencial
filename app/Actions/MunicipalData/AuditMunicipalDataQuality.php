<?php

namespace App\Actions\MunicipalData;

use App\Enums\QualityStatus;
use App\Models\IndicatorObservation;
use App\Models\ObservationFlag;
use App\Support\MunicipalData\IndicatorValueRangeValidator;
use Illuminate\Database\Eloquent\Builder;

class AuditMunicipalDataQuality
{
    public function __construct(
        private readonly IndicatorValueRangeValidator $rangeValidator,
    ) {}

    /** @return array{checked: int, warnings: int, rejected: int} */
    public function execute(int $referenceYear, ?string $sourceSlug = null): array
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
                $indicator = $observation->indicatorVersion->indicator;
                $violation = $this->rangeValidator->violation($observation->value, $indicator->unit);

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
}
