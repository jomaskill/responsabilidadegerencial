<?php

namespace App\Actions\MunicipalData;

use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\MunicipalData\IdebFetcher;
use App\MunicipalData\ImportSummary;
use App\MunicipalData\Parsers\IdebWorkbookParser;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class ImportInepIdeb
{
    public function __construct(
        private readonly IdebFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
        private readonly IdebWorkbookParser $parser,
    ) {}

    public function execute(int $fromYear, int $toYear): ImportSummary
    {
        if ($fromYear > $toYear) {
            throw new RuntimeException('The initial year must be less than or equal to the final year.');
        }

        $cycles = array_values(array_filter(
            array_map('intval', (array) config('municipal_data.ideb.cycles')),
            fn (int $year): bool => $year >= $fromYear && $year <= $toYear,
        ));

        if ($cycles === []) {
            return new ImportSummary(0, 0, 0, 0);
        }

        $configuredDatasets = config('municipal_data.ideb.datasets');

        if (! is_array($configuredDatasets) || $configuredDatasets === []) {
            throw new RuntimeException('No IDEB datasets are configured.');
        }

        $source = DataSource::query()->where('slug', 'inep-ideb')->firstOrFail();
        $artifact = $this->fetcher->fetch();
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $parsedDatasets = $this->parser->datasets($artifact->contents);
        $datasets = [];

        foreach ($configuredDatasets as $slug => $configuration) {
            if (! is_array($configuration) || ! isset($parsedDatasets[$slug])) {
                throw new RuntimeException("Invalid IDEB dataset configuration: {$slug}");
            }

            $sourceRows = $parsedDatasets[$slug];
            $expectedRows = (int) $configuration['expected_source_rows'];

            if (count($sourceRows) !== $expectedRows) {
                throw new RuntimeException("IDEB source coverage failed for {$slug}: expected {$expectedRows}, found ".count($sourceRows).'.');
            }

            foreach ($sourceRows as $sourceRow) {
                $code = $sourceRow['municipality_code'];

                if (isset($datasets[$slug][$code])) {
                    throw new RuntimeException("Duplicate IDEB municipality in {$slug}: {$code}");
                }

                $datasets[$slug][$code] = $sourceRow['values'];
            }
        }

        $inputRows = 0;
        $acceptedRows = 0;
        $createdRows = 0;

        foreach ($cycles as $year) {
            $summary = $this->importYear(
                $source,
                $artifact->sourceUrl,
                $stored,
                $configuredDatasets,
                $datasets,
                $year,
            );
            $inputRows += $summary->inputRows;
            $acceptedRows += $summary->acceptedRows;
            $createdRows += $summary->createdRows;
        }

        return new ImportSummary($inputRows, $acceptedRows, 0, $createdRows);
    }

    /**
     * @param  array{disk: string, path: string, checksum: string, mime_type: string, size_bytes: int}  $stored
     * @param  array<string, array<string, int|string>>  $configuredDatasets
     * @param  array<string, array<int, array<int, string>>>  $datasets
     */
    private function importYear(
        DataSource $source,
        string $sourceUrl,
        array $stored,
        array $configuredDatasets,
        array $datasets,
        int $year,
    ): ImportSummary {
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'inep_ideb_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'reference_year' => $year,
                'network' => 'Municipal',
                'artifact_checksum' => $stored['checksum'],
            ],
        ]);

        try {
            $municipalities = $this->municipalityRegistry($year);
            $expectedMunicipalities = (int) config('municipal_data.ideb.expected_municipalities');

            if (count($municipalities) !== $expectedMunicipalities) {
                throw new RuntimeException("IDEB municipality registry coverage failed for {$year}.");
            }

            foreach ($datasets as $slug => $municipalityResults) {
                foreach (array_keys($municipalityResults) as $municipalityCode) {
                    if (! isset($municipalities[$municipalityCode])) {
                        throw new RuntimeException("IDEB municipality is absent from the registry: {$municipalityCode}");
                    }
                }
            }

            $inputRows = array_sum(array_map('count', $datasets));
            $createdRows = DB::transaction(function () use (
                $source,
                $sourceUrl,
                $stored,
                $configuredDatasets,
                $datasets,
                $year,
                $run,
                $municipalities,
            ): int {
                $release = SourceRelease::query()->firstOrCreate(
                    [
                        'data_source_id' => $source->id,
                        'reference_year' => $year,
                        'version' => 'official-'.substr($stored['checksum'], 0, 16),
                    ],
                    [
                        'status' => ReleaseStatus::Final,
                        'published_at' => (string) config('municipal_data.ideb.published_at'),
                        'collected_at' => now()->toDateString(),
                        'source_url' => $sourceUrl,
                        'artifact_disk' => $stored['disk'],
                        'artifact_path' => $stored['path'],
                        'checksum_sha256' => $stored['checksum'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'metadata' => [
                            'network' => 'Municipal',
                            'reference_year' => $year,
                            'datasets' => $configuredDatasets,
                        ],
                    ],
                );
                $run->update(['source_release_id' => $release->id]);
                $createdRows = $this->insertObservations(
                    $configuredDatasets,
                    $datasets,
                    $municipalities,
                    $release,
                    $run,
                    $year,
                );
                $acceptedRows = count($municipalities) * count($configuredDatasets);
                $run->update([
                    'status' => ProcessingStatus::Completed,
                    'finished_at' => now(),
                    'input_rows' => array_sum(array_map('count', $datasets)),
                    'accepted_rows' => $acceptedRows,
                    'rejected_rows' => 0,
                ]);

                if ($release->wasRecentlyCreated) {
                    SourceRelease::query()
                        ->where('data_source_id', $source->id)
                        ->where('reference_year', $year)
                        ->where('id', '!=', $release->id)
                        ->whereNull('superseded_by_id')
                        ->update(['superseded_by_id' => $release->id]);
                }

                return $createdRows;
            });

            return new ImportSummary(
                $inputRows,
                count($municipalities) * count($configuredDatasets),
                0,
                $createdRows,
            );
        } catch (Throwable $exception) {
            $run->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'rejected_rows' => 1,
                'error_summary' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, array<string, int|string>>  $configuredDatasets
     * @param  array<string, array<int, array<int, string>>>  $datasets
     * @param  array<int, int>  $municipalities
     *
     * @throws JsonException
     */
    private function insertObservations(
        array $configuredDatasets,
        array $datasets,
        array $municipalities,
        SourceRelease $release,
        ProcessingRun $run,
        int $year,
    ): int {
        $createdRows = 0;
        $batch = [];
        $now = now();

        foreach ($configuredDatasets as $slug => $configuration) {
            $version = $this->versionFor($slug);

            foreach ($municipalities as $municipalityCode => $municipalityId) {
                $isPresent = isset($datasets[$slug][$municipalityCode]);
                $rawValue = $isPresent ? ($datasets[$slug][$municipalityCode][$year] ?? '-') : null;
                [$value, $availability, $sourceMarker] = $this->normalizedResult($rawValue, $isPresent);
                $batch[] = [
                    'observation_key' => hash('sha256', implode('|', [
                        $municipalityId,
                        $version->id,
                        $release->id,
                        $year,
                        '',
                        '',
                    ])),
                    'municipality_id' => $municipalityId,
                    'indicator_version_id' => $version->id,
                    'source_release_id' => $release->id,
                    'processing_run_id' => $run->id,
                    'reference_year' => $year,
                    'value' => $value,
                    'availability_status' => $availability->value,
                    'quality_status' => QualityStatus::Accepted->value,
                    'metadata' => json_encode([
                        'network' => 'Municipal',
                        'stage' => $configuration['stage'],
                        'source_url' => $configuration['url'],
                        'source_marker' => $sourceMarker,
                    ], JSON_THROW_ON_ERROR),
                    'observed_at' => $now,
                    'created_at' => $now,
                ];

                if (count($batch) === 500) {
                    $createdRows += IndicatorObservation::query()->insertOrIgnore($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            $createdRows += IndicatorObservation::query()->insertOrIgnore($batch);
        }

        return $createdRows;
    }

    /** @return array{float|null, AvailabilityStatus, string|null} */
    private function normalizedResult(?string $rawValue, bool $isPresent): array
    {
        if (! $isPresent) {
            return [null, AvailabilityStatus::NotApplicable, null];
        }

        if ($rawValue === null || $rawValue === '' || $rawValue === '-') {
            return [null, AvailabilityStatus::MissingFromSource, $rawValue ?: null];
        }

        if (! is_numeric($rawValue)) {
            throw new RuntimeException("Unsupported IDEB result marker: {$rawValue}");
        }

        $value = (float) $rawValue;

        if ($value < 0 || $value > 10) {
            throw new RuntimeException("IDEB value is outside 0-10: {$rawValue}");
        }

        return [$value, AvailabilityStatus::Available, null];
    }

    /** @return array<int, int> */
    private function municipalityRegistry(int $year): array
    {
        $municipalities = [];

        foreach (DB::table('municipalities')->where('is_active', true)->cursor() as $municipality) {
            $installedAt = is_string($municipality->installed_at) ? $municipality->installed_at : null;

            if ($installedAt !== null && (int) substr($installedAt, 0, 4) > $year) {
                continue;
            }

            $municipalities[(int) $municipality->ibge_code] = (int) $municipality->id;
        }

        return $municipalities;
    }

    private function versionFor(string $slug): IndicatorVersion
    {
        return IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', $slug))
            ->latest('version')
            ->firstOrFail();
    }
}
