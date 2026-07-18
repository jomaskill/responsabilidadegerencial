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
use App\MunicipalData\GdpFetcher;
use App\MunicipalData\ImportSummary;
use App\MunicipalData\Parsers\GdpArchiveParser;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class ImportIbgeGdp
{
    public function __construct(
        private readonly GdpFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
        private readonly GdpArchiveParser $parser,
    ) {}

    public function execute(int $fromYear, int $toYear): ImportSummary
    {
        if ($fromYear > $toYear) {
            throw new RuntimeException('The initial year must be less than or equal to the final year.');
        }

        $years = $this->configuredYears($fromYear, $toYear);

        if ($years === []) {
            return new ImportSummary(0, 0, 0, 0);
        }

        $source = DataSource::query()->where('slug', 'ibge-pib-municipios')->firstOrFail();
        $versions = [
            'gdp_nominal' => $this->versionFor('gdp_nominal'),
            'gdp_per_capita' => $this->versionFor('gdp_per_capita'),
        ];
        $artifact = $this->fetcher->fetch();
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $recordsByYear = array_fill_keys($years, []);

        foreach ($this->parser->rows($artifact->contents) as $record) {
            if (! isset($recordsByYear[$record['year']])) {
                continue;
            }

            $recordsByYear[$record['year']][(int) $record['municipality_code']] = [
                'gdp_nominal' => $this->gdpInReais($record['gdp_thousand_reais']),
                'gdp_per_capita' => $record['gdp_per_capita_reais'],
            ];
        }

        $inputRows = 0;
        $acceptedRows = 0;
        $createdRows = 0;

        foreach ($recordsByYear as $year => $records) {
            $summary = $this->importYear($source, $versions, $artifact->sourceUrl, $stored, $year, $records);
            $inputRows += $summary->inputRows;
            $acceptedRows += $summary->acceptedRows;
            $createdRows += $summary->createdRows;
        }

        return new ImportSummary($inputRows, $acceptedRows, 0, $createdRows);
    }

    /**
     * @param  array<string, IndicatorVersion>  $versions
     * @param  array{disk: string, path: string, checksum: string, mime_type: string, size_bytes: int}  $stored
     * @param  array<int, array{gdp_nominal: int, gdp_per_capita: string}>  $records
     */
    private function importYear(
        DataSource $source,
        array $versions,
        string $sourceUrl,
        array $stored,
        int $year,
        array $records,
    ): ImportSummary {
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'ibge_gdp_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'reference_year' => $year,
                'artifact_checksum' => $stored['checksum'],
                'source_entry' => config('municipal_data.gdp.entry'),
            ],
        ]);

        try {
            $configuration = config("municipal_data.gdp.years.{$year}");

            if (! is_array($configuration)) {
                throw new RuntimeException("No official GDP configuration exists for {$year}.");
            }

            $municipalities = $this->municipalityRegistry($year);
            $expected = (int) $configuration['expected_municipalities'];

            if (count($records) !== $expected || count($municipalities) !== $expected) {
                throw new RuntimeException("GDP coverage failed for {$year}: expected {$expected}, source ".count($records).', registry '.count($municipalities).'.');
            }

            foreach (array_keys($records) as $municipalityCode) {
                if (! isset($municipalities[$municipalityCode])) {
                    throw new RuntimeException("GDP municipality is absent from the registry: {$municipalityCode}");
                }
            }

            $createdRows = DB::transaction(function () use (
                $source,
                $versions,
                $sourceUrl,
                $stored,
                $year,
                $records,
                $municipalities,
                $configuration,
                $run,
            ): int {
                $release = SourceRelease::query()->firstOrCreate(
                    [
                        'data_source_id' => $source->id,
                        'reference_year' => $year,
                        'version' => 'official-'.substr($stored['checksum'], 0, 16),
                    ],
                    [
                        'status' => ReleaseStatus::Final,
                        'published_at' => (string) $configuration['published_at'],
                        'collected_at' => now()->toDateString(),
                        'source_url' => $sourceUrl,
                        'artifact_disk' => $stored['disk'],
                        'artifact_path' => $stored['path'],
                        'checksum_sha256' => $stored['checksum'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'metadata' => $this->releaseMetadata($year),
                    ],
                );
                $run->update(['source_release_id' => $release->id]);
                $createdRows = $this->insertObservations($records, $municipalities, $versions, $release, $run, $year);
                $run->update([
                    'status' => ProcessingStatus::Completed,
                    'finished_at' => now(),
                    'input_rows' => count($records),
                    'accepted_rows' => count($records),
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

            return new ImportSummary(count($records), count($records), 0, $createdRows);
        } catch (Throwable $exception) {
            $run->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'input_rows' => count($records),
                'accepted_rows' => 0,
                'rejected_rows' => count($records),
                'error_summary' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{gdp_nominal: int, gdp_per_capita: string}>  $records
     * @param  array<int, int>  $municipalities
     * @param  array<string, IndicatorVersion>  $versions
     *
     * @throws JsonException
     */
    private function insertObservations(
        array $records,
        array $municipalities,
        array $versions,
        SourceRelease $release,
        ProcessingRun $run,
        int $year,
    ): int {
        $createdRows = 0;
        $batch = [];
        $now = now();

        foreach ($records as $municipalityCode => $values) {
            foreach ($values as $slug => $value) {
                $version = $versions[$slug];
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
                    'value' => $value,
                    'availability_status' => AvailabilityStatus::Available->value,
                    'quality_status' => QualityStatus::Accepted->value,
                    'metadata' => json_encode([
                        ...$this->releaseMetadata($year),
                        'source_column' => $slug === 'gdp_nominal' ? 39 : 40,
                        'source_unit' => $slug === 'gdp_nominal' ? 'R$' : 'R$/habitante',
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

    private function gdpInReais(string $thousandReais): int
    {
        if (str_contains($thousandReais, '.')) {
            return (int) str_replace('.', '', $thousandReais);
        }

        return (int) $thousandReais * 1000;
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

    /** @return array<string, int|string> */
    private function releaseMetadata(int $year): array
    {
        return [
            'methodology_url' => (string) config('municipal_data.gdp.methodology_url'),
            'source_entry' => (string) config('municipal_data.gdp.entry'),
            'price_basis' => 'current_prices',
            'reference_year' => $year,
            'series_caveat' => $year >= 2022
                ? 'Série ano-base 2010; estimativas 2022-2023 serão reapresentadas na nova série prevista para 2027.'
                : 'Série ano-base 2010.',
        ];
    }

    private function versionFor(string $slug): IndicatorVersion
    {
        return IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', $slug))
            ->latest('version')
            ->firstOrFail();
    }

    /** @return array<int, int> */
    private function configuredYears(int $fromYear, int $toYear): array
    {
        $configured = array_map('intval', array_keys((array) config('municipal_data.gdp.years')));

        return array_values(array_filter(
            $configured,
            fn (int $year): bool => $year >= $fromYear && $year <= $toYear,
        ));
    }
}
