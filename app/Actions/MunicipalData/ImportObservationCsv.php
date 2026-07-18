<?php

namespace App\Actions\MunicipalData;

use App\DTO\MunicipalData\ImportObservationData;
use App\DTO\MunicipalData\ImportSummary;
use App\DTO\MunicipalData\StoredSourceArtifact;
use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\Municipality;
use App\Models\ProcessingError;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\Support\MunicipalData\Parsers\DelimitedSourceParser;
use App\Support\MunicipalData\Transformers\CanonicalObservationTransformer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;
use Throwable;

class ImportObservationCsv
{
    public function __construct(
        private readonly StoreSourceArtifact $artifactStore,
        private readonly DelimitedSourceParser $parser,
        private readonly CanonicalObservationTransformer $transformer,
    ) {}

    public function execute(ImportObservationData $data): ImportSummary
    {
        $sourceSlug = $data->sourceSlug;
        $filePath = $data->filePath;
        $fromYear = $data->period->fromYear;
        $toYear = $data->period->toYear;
        $releaseStatus = $data->releaseStatus;
        $releaseVersion = $data->releaseVersion;
        $delimiter = $data->delimiter;
        $sourceUrl = $data->sourceUrl;

        $source = DataSource::query()->where('slug', $sourceSlug)->firstOrFail();
        $artifact = $this->artifactStore->fromFile($source, $filePath);
        $years = $this->yearsInFile($filePath, $delimiter, $fromYear, $toYear);

        if ($years === []) {
            throw new RuntimeException('No records were found inside the requested period.');
        }

        $releases = $this->releases(
            source: $source,
            years: $years,
            version: $releaseVersion,
            status: $releaseStatus,
            artifact: $artifact,
            sourceUrl: $sourceUrl,
            fromYear: $fromYear,
            toYear: $toYear,
        );

        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'source_release_id' => count($releases) === 1 ? array_values($releases)[0]->id : null,
            'type' => 'observation_csv_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => compact('sourceSlug', 'fromYear', 'toYear', 'releaseVersion', 'delimiter'),
        ]);

        $inputRows = 0;
        $acceptedRows = 0;
        $rejectedRows = 0;
        $createdRows = 0;

        try {
            foreach ($this->parser->records($filePath, ['delimiter' => $delimiter]) as $rowNumber => $record) {
                $inputRows++;

                try {
                    $observation = $this->transformer->transform($record);
                    $year = $observation['reference_year'];

                    if ($year < $fromYear || $year > $toYear) {
                        continue;
                    }

                    $municipality = Municipality::query()->where('ibge_code', $observation['municipality_code'])->first();
                    $indicatorVersion = IndicatorVersion::query()
                        ->where('version', $observation['indicator_version'])
                        ->whereHas('indicator', fn ($query) => $query->where('slug', $observation['indicator_slug']))
                        ->first();

                    if ($municipality === null) {
                        throw new ModelNotFoundException("Unknown IBGE municipality code: {$observation['municipality_code']}");
                    }

                    if ($indicatorVersion === null) {
                        throw new ModelNotFoundException("Unknown indicator or version: {$observation['indicator_slug']} v{$observation['indicator_version']}");
                    }

                    $availability = $observation['availability_status'];

                    if ($releaseStatus === ReleaseStatus::Provisional && $availability === AvailabilityStatus::Available) {
                        $availability = AvailabilityStatus::Provisional;
                    }

                    $key = $this->observationKey(
                        municipalityId: $municipality->id,
                        indicatorVersionId: $indicatorVersion->id,
                        sourceReleaseId: $releases[$year]->id,
                        referenceYear: $year,
                        periodStart: $observation['period_start'],
                        periodEnd: $observation['period_end'],
                    );

                    $model = IndicatorObservation::query()->firstOrCreate(
                        ['observation_key' => $key],
                        [
                            'municipality_id' => $municipality->id,
                            'indicator_version_id' => $indicatorVersion->id,
                            'source_release_id' => $releases[$year]->id,
                            'processing_run_id' => $run->id,
                            'reference_year' => $year,
                            'period_start' => $observation['period_start'],
                            'period_end' => $observation['period_end'],
                            'value' => $observation['value'],
                            'numerator' => $observation['numerator'],
                            'denominator' => $observation['denominator'],
                            'availability_status' => $availability,
                            'quality_status' => $observation['quality_status'],
                            'notes' => $observation['notes'],
                            'metadata' => ['source_row' => $rowNumber],
                            'observed_at' => now(),
                        ],
                    );

                    $acceptedRows++;
                    $createdRows += (int) $model->wasRecentlyCreated;
                } catch (Throwable $exception) {
                    $rejectedRows++;
                    ProcessingError::query()->create([
                        'processing_run_id' => $run->id,
                        'row_number' => $rowNumber,
                        'municipality_code' => $record['municipality_code'] ?? null,
                        'indicator_slug' => $record['indicator_slug'] ?? null,
                        'code' => 'invalid_observation',
                        'message' => $exception->getMessage(),
                        'payload' => $record,
                    ]);
                }
            }

            $run->update([
                'status' => ProcessingStatus::Completed,
                'finished_at' => now(),
                'input_rows' => $inputRows,
                'accepted_rows' => $acceptedRows,
                'rejected_rows' => $rejectedRows,
            ]);

            return new ImportSummary($inputRows, $acceptedRows, $rejectedRows, $createdRows);
        } catch (Throwable $exception) {
            $run->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'input_rows' => $inputRows,
                'accepted_rows' => $acceptedRows,
                'rejected_rows' => $rejectedRows,
                'error_summary' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @return array<int, int> */
    private function yearsInFile(string $filePath, string $delimiter, int $fromYear, int $toYear): array
    {
        $years = [];

        foreach ($this->parser->records($filePath, ['delimiter' => $delimiter]) as $record) {
            $year = filter_var($record['reference_year'] ?? null, FILTER_VALIDATE_INT);

            if ($year !== false && $year >= $fromYear && $year <= $toYear) {
                $years[$year] = $year;
            }
        }

        ksort($years);

        return $years;
    }

    /**
     * @param  array<int, int>  $years
     * @return array<int, SourceRelease>
     */
    private function releases(
        DataSource $source,
        array $years,
        string $version,
        ReleaseStatus $status,
        StoredSourceArtifact $artifact,
        ?string $sourceUrl,
        int $fromYear,
        int $toYear,
    ): array {
        $releases = [];

        foreach ($years as $year) {
            $release = SourceRelease::query()->firstOrCreate(
                ['data_source_id' => $source->id, 'reference_year' => $year, 'version' => $version],
                [
                    'status' => $status,
                    'collected_at' => now()->toDateString(),
                    'source_url' => $sourceUrl,
                    'artifact_disk' => $artifact->disk,
                    'artifact_path' => $artifact->path,
                    'checksum_sha256' => $artifact->checksum,
                    'mime_type' => $artifact->mimeType,
                    'size_bytes' => $artifact->sizeBytes,
                    'metadata' => ['import_from_year' => $fromYear, 'import_to_year' => $toYear],
                ],
            );

            if (! $release->wasRecentlyCreated && $release->checksum_sha256 !== $artifact->checksum) {
                throw new RuntimeException("Release {$source->slug}/{$year}/{$version} already exists with another checksum. Use a new --release-version.");
            }

            if ($release->wasRecentlyCreated) {
                SourceRelease::query()
                    ->where('data_source_id', $source->id)
                    ->where('reference_year', $year)
                    ->where('id', '!=', $release->id)
                    ->whereNull('superseded_by_id')
                    ->update(['superseded_by_id' => $release->id]);
            }

            $releases[$year] = $release;
        }

        return $releases;
    }

    private function observationKey(
        int $municipalityId,
        int $indicatorVersionId,
        int $sourceReleaseId,
        int $referenceYear,
        ?string $periodStart,
        ?string $periodEnd,
    ): string {
        return hash('sha256', implode('|', [
            $municipalityId,
            $indicatorVersionId,
            $sourceReleaseId,
            $referenceYear,
            $periodStart ?? '',
            $periodEnd ?? '',
        ]));
    }
}
