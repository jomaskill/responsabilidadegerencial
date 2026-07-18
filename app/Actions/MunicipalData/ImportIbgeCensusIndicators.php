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
use App\MunicipalData\CensusIndicatorFetcher;
use App\MunicipalData\ImportSummary;
use App\MunicipalData\Parsers\CensusIndicatorArchiveParser;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class ImportIbgeCensusIndicators
{
    public function __construct(
        private readonly CensusIndicatorFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
        private readonly CensusIndicatorArchiveParser $parser,
    ) {}

    public function execute(): ImportSummary
    {
        $year = (int) config('municipal_data.census_indicators.reference_year');
        $expected = (int) config('municipal_data.census_indicators.expected_municipalities');
        $configuredDatasets = config('municipal_data.census_indicators.datasets');

        if (! is_array($configuredDatasets) || $configuredDatasets === []) {
            throw new RuntimeException('No Census indicator datasets are configured.');
        }

        $source = DataSource::query()->where('slug', 'ibge-censo-2022')->firstOrFail();
        $artifact = $this->fetcher->fetch();
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'ibge_census_indicator_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'reference_year' => $year,
                'indicator_slugs' => array_keys($configuredDatasets),
                'artifact_checksum' => $stored['checksum'],
            ],
        ]);

        try {
            $municipalities = $this->municipalityRegistry($year);

            if (count($municipalities) !== $expected) {
                throw new RuntimeException("Census municipality registry coverage failed: expected {$expected}, found ".count($municipalities).'.');
            }

            $records = [];
            $datasets = $this->parser->datasets($artifact->contents);

            foreach ($configuredDatasets as $slug => $configuration) {
                if (! is_array($configuration) || ! isset($datasets[$slug])) {
                    throw new RuntimeException("Census dataset configuration is invalid: {$slug}");
                }

                $datasetRecords = $this->datasetRecords($datasets[$slug], (string) $slug);

                if (count($datasetRecords) !== $expected) {
                    throw new RuntimeException("Census coverage failed for {$slug}: expected {$expected}, found ".count($datasetRecords).'.');
                }

                foreach ($datasetRecords as $municipalityCode => $value) {
                    if (! isset($municipalities[$municipalityCode])) {
                        throw new RuntimeException("Census municipality is absent from the registry: {$municipalityCode}");
                    }

                    $records[$municipalityCode][(string) $slug] = $value;
                }
            }

            $inputRows = array_sum(array_map('count', $records));
            $createdRows = DB::transaction(function () use (
                $source,
                $artifact,
                $stored,
                $run,
                $year,
                $records,
                $municipalities,
                $configuredDatasets,
            ): int {
                $release = SourceRelease::query()->firstOrCreate(
                    [
                        'data_source_id' => $source->id,
                        'reference_year' => $year,
                        'version' => 'official-'.substr($stored['checksum'], 0, 16),
                    ],
                    [
                        'status' => ReleaseStatus::Final,
                        'published_at' => (string) config('municipal_data.census_indicators.published_at'),
                        'collected_at' => now()->toDateString(),
                        'source_url' => $artifact->sourceUrl,
                        'artifact_disk' => $stored['disk'],
                        'artifact_path' => $stored['path'],
                        'checksum_sha256' => $stored['checksum'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'metadata' => [
                            'reference_year' => $year,
                            'datasets' => $configuredDatasets,
                        ],
                    ],
                );
                $run->update(['source_release_id' => $release->id]);
                $createdRows = $this->insertObservations(
                    $records,
                    $municipalities,
                    $release,
                    $run,
                    $year,
                    $configuredDatasets,
                );
                $acceptedRows = array_sum(array_map('count', $records));
                $run->update([
                    'status' => ProcessingStatus::Completed,
                    'finished_at' => now(),
                    'input_rows' => $acceptedRows,
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

            return new ImportSummary($inputRows, $inputRows, 0, $createdRows);
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

    /** @return array<int, array{value: string, source_marker: string|null}> */
    private function datasetRecords(string $contents, string $slug): array
    {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Census dataset is not a JSON array: {$slug}");
        }

        $records = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = $row['D1C'] ?? null;

            if (! is_string($code) || preg_match('/^\d{7}$/', $code) !== 1) {
                continue;
            }

            $rawValue = $row['V'] ?? null;

            if (! is_string($rawValue)) {
                throw new RuntimeException("Invalid Census percentage for {$slug} and municipality {$code}.");
            }

            $sourceMarker = null;

            if ($rawValue === '-') {
                $sourceMarker = '-';
                $rawValue = '0';
            } elseif (! is_numeric($rawValue)) {
                throw new RuntimeException("Unsupported Census marker for {$slug} and municipality {$code}: {$rawValue}");
            }

            $value = (float) $rawValue;

            if ($value < 0 || $value > 100) {
                throw new RuntimeException("Census percentage is outside 0-100 for {$slug} and municipality {$code}.");
            }

            $municipalityCode = (int) $code;

            if (isset($records[$municipalityCode])) {
                throw new RuntimeException("Duplicate Census municipality for {$slug}: {$code}");
            }

            $records[$municipalityCode] = [
                'value' => $rawValue,
                'source_marker' => $sourceMarker,
            ];
        }

        return $records;
    }

    /**
     * @param  array<int, array<string, array{value: string, source_marker: string|null}>>  $records
     * @param  array<int, int>  $municipalities
     * @param  array<string, array<string, int|string>>  $configuredDatasets
     *
     * @throws JsonException
     */
    private function insertObservations(
        array $records,
        array $municipalities,
        SourceRelease $release,
        ProcessingRun $run,
        int $year,
        array $configuredDatasets,
    ): int {
        $versions = [];

        foreach (array_keys($configuredDatasets) as $slug) {
            $versions[$slug] = $this->versionFor($slug);
        }

        $createdRows = 0;
        $batch = [];
        $now = now();

        foreach ($records as $municipalityCode => $values) {
            foreach ($values as $slug => $result) {
                $version = $versions[$slug];
                $configuration = $configuredDatasets[$slug];
                $batch[] = [
                    'observation_key' => hash('sha256', implode('|', [
                        $municipalities[$municipalityCode],
                        $version->id,
                        $release->id,
                        $year,
                        '',
                        '',
                    ])),
                    'municipality_id' => $municipalities[$municipalityCode],
                    'indicator_version_id' => $version->id,
                    'source_release_id' => $release->id,
                    'processing_run_id' => $run->id,
                    'reference_year' => $year,
                    'value' => $result['value'],
                    'availability_status' => AvailabilityStatus::Available->value,
                    'quality_status' => QualityStatus::Accepted->value,
                    'metadata' => json_encode([
                        'table' => $configuration['table'],
                        'category_code' => $configuration['category_code'],
                        'definition' => $configuration['definition'],
                        'source_url' => $configuration['url'],
                        'unit' => 'percent',
                        'source_marker' => $result['source_marker'],
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
