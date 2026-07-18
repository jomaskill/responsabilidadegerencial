<?php

namespace App\Actions\MunicipalData;

use App\DTO\MunicipalData\ImportPeriod;
use App\DTO\MunicipalData\ImportSummary;
use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;

class RecalculateHomicideIndicators
{
    public function execute(ImportPeriod $period, ?string $indicatorSlug = null): ImportSummary
    {
        $fromYear = $period->fromYear;
        $toYear = $period->toYear;
        $supported = ['homicide_rate', 'homicide_rate_rolling_3y'];
        $requested = $indicatorSlug === null ? $supported : [$indicatorSlug];

        if (array_diff($requested, $supported) !== []) {
            throw new InvalidArgumentException('Supported calculated indicators: '.implode(', ', $supported));
        }

        $source = DataSource::query()->where('slug', 'system-calculated')->firstOrFail();
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'indicator_calculation',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => compact('fromYear', 'toYear', 'indicatorSlug'),
        ]);
        $inputRows = 0;
        $acceptedRows = 0;
        $createdRows = 0;

        try {
            foreach ($requested as $slug) {
                foreach ($period->years() as $year) {
                    $records = $slug === 'homicide_rate'
                        ? $this->annualRecords($year)
                        : $this->rollingRecords($year);

                    if ($records === []) {
                        continue;
                    }

                    $version = $this->versionFor($slug);
                    $release = $this->releaseFor($source, $slug, $year, $records);

                    $stored = $this->storeRecords($records, $version, $release, $run, $year);
                    $inputRows += $stored['input_rows'];
                    $acceptedRows += $stored['accepted_rows'];
                    $createdRows += $stored['created_rows'];
                }
            }

            $run->update([
                'status' => ProcessingStatus::Completed,
                'finished_at' => now(),
                'input_rows' => $inputRows,
                'accepted_rows' => $acceptedRows,
            ]);

            return new ImportSummary($inputRows, $acceptedRows, 0, $createdRows);
        } catch (Throwable $exception) {
            $run->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'input_rows' => $inputRows,
                'accepted_rows' => $acceptedRows,
                'error_summary' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{input_rows: int, accepted_rows: int, created_rows: int}
     *
     * @throws JsonException
     */
    private function storeRecords(
        array $records,
        IndicatorVersion $version,
        SourceRelease $release,
        ProcessingRun $run,
        int $year,
    ): array {
        $rows = [];
        $recordsByKey = [];
        $inputRows = 0;
        $createdRows = 0;
        $now = now();

        foreach ($records as $record) {
            $key = hash('sha256', implode('|', [
                $record['municipality_id'],
                $version->id,
                $release->id,
                $year,
                $record['period_start'],
                $record['period_end'],
            ]));
            $recordsByKey[$key] = $record;
            $rows[] = [
                'observation_key' => $key,
                'municipality_id' => $record['municipality_id'],
                'indicator_version_id' => $version->id,
                'source_release_id' => $release->id,
                'processing_run_id' => $run->id,
                'reference_year' => $year,
                'period_start' => $record['period_start'],
                'period_end' => $record['period_end'],
                'value' => round(($record['numerator'] / $record['denominator']) * 100000, 8),
                'numerator' => $record['numerator'],
                'denominator' => $record['denominator'],
                'availability_status' => ($record['provisional'] ? AvailabilityStatus::Provisional : AvailabilityStatus::Available)->value,
                'quality_status' => QualityStatus::Accepted->value,
                'metadata' => json_encode(['formula' => $version->formula], JSON_THROW_ON_ERROR),
                'observed_at' => $now,
                'created_at' => $now,
            ];

            foreach ($record['inputs'] as $inputs) {
                $inputRows += count($inputs);
            }
        }

        foreach (array_chunk($rows, 500) as $batch) {
            $createdRows += IndicatorObservation::query()->insertOrIgnore($batch);
        }

        $storedObservations = [];

        foreach (array_chunk(array_keys($recordsByKey), 500) as $keys) {
            IndicatorObservation::query()
                ->whereIn('observation_key', $keys)
                ->get(['id', 'observation_key'])
                ->each(function (IndicatorObservation $observation) use (&$storedObservations): void {
                    $storedObservations[$observation->observation_key] = $observation->id;
                });
        }

        $inputRowsToInsert = [];

        foreach ($recordsByKey as $key => $record) {
            $observationId = $storedObservations[$key] ?? null;

            if ($observationId === null) {
                throw new InvalidArgumentException("Calculated observation was not stored: {$key}");
            }

            foreach ($record['inputs'] as $role => $inputs) {
                foreach ($inputs as $input) {
                    $inputRowsToInsert[] = [
                        'indicator_observation_id' => $observationId,
                        'input_indicator_observation_id' => $input->id,
                        'role' => $role,
                        'created_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($inputRowsToInsert, 500) as $batch) {
            DB::table('observation_inputs')->insertOrIgnore($batch);
        }

        return [
            'input_rows' => $inputRows,
            'accepted_rows' => count($records),
            'created_rows' => $createdRows,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function annualRecords(int $year): array
    {
        $homicides = $this->currentObservations('homicide_count', [$year]);
        $populations = $this->currentObservations('population', [$year]);
        $records = [];

        foreach ($homicides as $key => $homicide) {
            $population = $populations->get($key);

            if ($population === null || (float) $population->value <= 0) {
                continue;
            }

            $records[] = [
                'municipality_id' => $homicide->municipality_id,
                'numerator' => (float) $homicide->value,
                'denominator' => (float) $population->value,
                'period_start' => "{$year}-01-01",
                'period_end' => "{$year}-12-31",
                'provisional' => $this->isProvisional([$homicide, $population]),
                'inputs' => ['numerator' => [$homicide], 'denominator' => [$population]],
            ];
        }

        return $records;
    }

    /** @return array<int, array<string, mixed>> */
    private function rollingRecords(int $year): array
    {
        $years = range($year - 2, $year);
        $homicides = $this->currentObservations('homicide_count', $years)->groupBy('municipality_id');
        $populations = $this->currentObservations('population', $years)->groupBy('municipality_id');
        $records = [];

        foreach ($homicides as $municipalityId => $municipalityHomicides) {
            $municipalityPopulations = $populations->get($municipalityId);

            if ($municipalityPopulations === null || $municipalityHomicides->count() !== 3 || $municipalityPopulations->count() !== 3) {
                continue;
            }

            $denominator = $municipalityPopulations->sum(
                fn (IndicatorObservation $observation): float => (float) $observation->value,
            );

            if ($denominator <= 0) {
                continue;
            }

            $allInputs = $municipalityHomicides->concat($municipalityPopulations)->all();
            $records[] = [
                'municipality_id' => (int) $municipalityId,
                'numerator' => $municipalityHomicides->sum(
                    fn (IndicatorObservation $observation): float => (float) $observation->value,
                ),
                'denominator' => $denominator,
                'period_start' => ($year - 2).'-01-01',
                'period_end' => "{$year}-12-31",
                'provisional' => $this->isProvisional($allInputs),
                'inputs' => ['numerator' => $municipalityHomicides->all(), 'denominator' => $municipalityPopulations->all()],
            ];
        }

        return $records;
    }

    /**
     * @param  array<int, int>  $years
     * @return Collection<string, IndicatorObservation>
     */
    private function currentObservations(string $slug, array $years): Collection
    {
        return IndicatorObservation::query()
            ->whereIn('reference_year', $years)
            ->whereIn('availability_status', [AvailabilityStatus::Available->value, AvailabilityStatus::Provisional->value])
            ->where('quality_status', '!=', QualityStatus::Rejected->value)
            ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', $slug))
            ->whereHas('sourceRelease', fn ($query) => $query->whereNull('superseded_by_id'))
            ->orderByDesc('id')
            ->get()
            ->unique(fn (IndicatorObservation $observation) => "{$observation->municipality_id}:{$observation->reference_year}")
            ->keyBy(fn (IndicatorObservation $observation) => "{$observation->municipality_id}:{$observation->reference_year}");
    }

    private function versionFor(string $slug): IndicatorVersion
    {
        return IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', $slug))
            ->orderByDesc('version')
            ->firstOrFail();
    }

    /** @param array<int, array<string, mixed>> $records */
    private function releaseFor(DataSource $source, string $slug, int $year, array $records): SourceRelease
    {
        $inputIds = [];
        $isProvisional = false;

        foreach ($records as $record) {
            $isProvisional = $isProvisional || $record['provisional'] === true;

            foreach ($record['inputs'] as $observations) {
                foreach ($observations as $observation) {
                    $inputIds[] = $observation->id;
                }
            }
        }

        $inputIds = array_values(array_unique($inputIds));
        sort($inputIds);
        $inputHash = hash('sha256', implode(',', $inputIds));
        $version = $slug.'-inputs-'.substr($inputHash, 0, 16);
        $release = SourceRelease::query()->firstOrCreate(
            ['data_source_id' => $source->id, 'reference_year' => $year, 'version' => $version],
            [
                'status' => $isProvisional ? ReleaseStatus::Provisional : ReleaseStatus::Final,
                'collected_at' => now()->toDateString(),
                'source_url' => 'internal://municipal-indicator-calculator',
                'metadata' => ['indicator_slug' => $slug, 'input_observation_ids_hash' => $inputHash],
            ],
        );

        if ($release->wasRecentlyCreated) {
            SourceRelease::query()
                ->where('data_source_id', $source->id)
                ->where('reference_year', $year)
                ->where('id', '!=', $release->id)
                ->where('metadata->indicator_slug', $slug)
                ->whereNull('superseded_by_id')
                ->update(['superseded_by_id' => $release->id]);
        }

        return $release;
    }

    /** @param array<int, IndicatorObservation> $observations */
    private function isProvisional(array $observations): bool
    {
        return collect($observations)->contains(
            fn (IndicatorObservation $observation) => $observation->availability_status === AvailabilityStatus::Provisional,
        );
    }
}
