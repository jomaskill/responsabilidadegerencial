<?php

namespace App\Actions\MunicipalData;

use App\Contracts\MunicipalData\SanitationFetcher;
use App\DTO\MunicipalData\ImportPeriod;
use App\DTO\MunicipalData\ImportSummary;
use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\Municipality;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\Support\MunicipalData\Parsers\SinisaSanitationArchiveParser;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class ImportSinisaSanitation
{
    public function __construct(
        private readonly SanitationFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
        private readonly SinisaSanitationArchiveParser $parser,
    ) {}

    public function execute(ImportPeriod $period): ImportSummary
    {
        $year = (int) config('municipal_data.sinisa.reference_year');

        if (! $period->contains($year)) {
            return new ImportSummary(0, 0, 0, 0);
        }

        $configuredDatasets = config('municipal_data.sinisa.datasets');

        if (! is_array($configuredDatasets) || $configuredDatasets === []) {
            throw new RuntimeException('No SINISA datasets are configured.');
        }

        $source = DataSource::query()->where('slug', 'sinisa')->firstOrFail();
        $artifact = $this->fetcher->fetch();
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $parsed = $this->parser->datasets($artifact->contents);
        $releaseVersion = $this->releaseVersion($configuredDatasets, $stored->checksum);
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'sinisa_sanitation_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'reference_year' => $year,
                'indicator_slugs' => array_keys($configuredDatasets),
                'artifact_checksum' => $stored->checksum,
                'release_version' => $releaseVersion,
            ],
        ]);

        try {
            $municipalities = $this->municipalityRegistry($year);
            $expectedMunicipalities = (int) config('municipal_data.sinisa.expected_municipalities');

            if (count($municipalities) !== $expectedMunicipalities) {
                throw new RuntimeException("SINISA municipality registry coverage failed: expected {$expectedMunicipalities}, found ".count($municipalities).'.');
            }

            foreach ($parsed['records'] as $municipalityResults) {
                foreach (array_keys($municipalityResults) as $municipalityCode) {
                    if (! isset($municipalities[$municipalityCode])) {
                        throw new RuntimeException("SINISA municipality is absent from the registry: {$municipalityCode}");
                    }
                }
            }

            $acceptedRows = count($municipalities) * count($configuredDatasets);
            $createdRows = DB::transaction(function () use (
                $source,
                $artifact,
                $stored,
                $run,
                $year,
                $configuredDatasets,
                $parsed,
                $municipalities,
                $acceptedRows,
                $releaseVersion,
            ): int {
                $release = SourceRelease::query()->firstOrCreate(
                    [
                        'data_source_id' => $source->id,
                        'reference_year' => $year,
                        'version' => $releaseVersion,
                    ],
                    [
                        'status' => ReleaseStatus::Final,
                        'published_at' => (string) config('municipal_data.sinisa.published_at'),
                        'collected_at' => now()->toDateString(),
                        'source_url' => $artifact->sourceUrl,
                        'artifact_disk' => $stored->disk,
                        'artifact_path' => $stored->path,
                        'checksum_sha256' => $stored->checksum,
                        'mime_type' => $stored->mimeType,
                        'size_bytes' => $stored->sizeBytes,
                        'metadata' => [
                            'reference_year' => $year,
                            'datasets' => $configuredDatasets,
                        ],
                    ],
                );
                $run->update(['source_release_id' => $release->id]);
                $createdRows = $this->insertObservations(
                    $configuredDatasets,
                    $parsed['records'],
                    $municipalities,
                    $release,
                    $run,
                    $year,
                );
                $run->update([
                    'status' => ProcessingStatus::Completed,
                    'finished_at' => now(),
                    'input_rows' => $parsed['input_rows'],
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

            return new ImportSummary($parsed['input_rows'], $acceptedRows, 0, $createdRows);
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
     * @param  array<string, array<string, mixed>>  $configuredDatasets
     * @param  array<string, array<int, array{raw_value: string, source_layer: string}>>  $records
     * @param  array<int, int>  $municipalities
     *
     * @throws JsonException
     */
    private function insertObservations(
        array $configuredDatasets,
        array $records,
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
                $result = $records[$slug][$municipalityCode] ?? null;
                [$value, $availability, $sourceMarker] = $this->normalizedResult($result['raw_value'] ?? null);
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
                        'indicator_code' => $configuration['indicator_code'],
                        'definition' => $configuration['definition'],
                        'source_url' => $configuration['url'],
                        'source_layer' => $result['source_layer'] ?? null,
                        'source_marker' => $sourceMarker,
                        'unit' => 'percent',
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
    private function normalizedResult(?string $rawValue): array
    {
        if ($rawValue === null) {
            return [null, AvailabilityStatus::MissingFromSource, 'municipality_absent_from_source'];
        }

        if (is_numeric($rawValue)) {
            $value = (float) $rawValue;

            if ($value < 0 || $value > 100) {
                throw new RuntimeException("SINISA percentage is outside 0-100: {$rawValue}");
            }

            return [$value, AvailabilityStatus::Available, null];
        }

        if ($rawValue === '' || str_starts_with($rawValue, 'Não Calc.')) {
            return [null, AvailabilityStatus::MissingFromSource, $rawValue ?: 'blank'];
        }

        throw new RuntimeException("Unsupported SINISA result marker: {$rawValue}");
    }

    /** @return array<int, int> */
    private function municipalityRegistry(int $year): array
    {
        $municipalities = [];

        foreach (Municipality::query()->where('is_active', true)->existingInYear($year)->cursor() as $municipality) {
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

    /** @param array<string, array<string, mixed>> $datasets */
    private function releaseVersion(array $datasets, string $fallbackChecksum): string
    {
        $officialChecksums = [];

        foreach ($datasets as $dataset) {
            $sources = [$dataset, ...(array) ($dataset['corrections'] ?? [])];

            foreach ($sources as $source) {
                $checksum = $source['sha256'] ?? null;

                if (! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                    return 'official-'.substr($fallbackChecksum, 0, 16);
                }

                $officialChecksums[] = $checksum;
            }
        }

        return 'official-'.substr(hash('sha256', implode('|', $officialChecksums)), 0, 16);
    }
}
