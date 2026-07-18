<?php

namespace App\Actions\MunicipalData;

use App\Contracts\MunicipalData\PopulationFetcher;
use App\DTO\MunicipalData\ImportPeriod;
use App\DTO\MunicipalData\ImportSummary;
use App\DTO\MunicipalData\PopulationSourceDefinition;
use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\ProcessingError;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\Support\MunicipalData\Parsers\PopulationSourceParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportIbgePopulation
{
    public function __construct(
        private readonly PopulationFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
        private readonly PopulationSourceParser $parser,
    ) {}

    public function execute(ImportPeriod $period): ImportSummary
    {
        $fromYear = $period->fromYear;
        $toYear = $period->toYear;

        $source = DataSource::query()->where('slug', 'ibge-populacao')->firstOrFail();
        $indicatorVersion = IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', 'population'))
            ->latest('version')
            ->firstOrFail();
        $municipalities = $this->municipalityRegistry();
        $totalInput = 0;
        $totalAccepted = 0;
        $totalRejected = 0;
        $totalCreated = 0;

        foreach ($period->years() as $year) {
            $definition = $this->definitionFor($year);

            if (count($municipalities) < $definition->expectedRecords) {
                throw new RuntimeException('Import the current IBGE municipality registry before population data.');
            }

            $summary = $this->importYear($source, $indicatorVersion, $municipalities, $definition);
            $totalInput += $summary->inputRows;
            $totalAccepted += $summary->acceptedRows;
            $totalRejected += $summary->rejectedRows;
            $totalCreated += $summary->createdRows;
        }

        return new ImportSummary($totalInput, $totalAccepted, $totalRejected, $totalCreated);
    }

    /** @return array<string, array{id: int, installed_at: string|null}> */
    private function municipalityRegistry(): array
    {
        $municipalities = [];

        foreach (DB::table('municipalities')->select(['id', 'ibge_code', 'installed_at'])->cursor() as $municipality) {
            $municipalities[(string) $municipality->ibge_code] = [
                'id' => (int) $municipality->id,
                'installed_at' => is_string($municipality->installed_at) ? $municipality->installed_at : null,
            ];
        }

        return $municipalities;
    }

    /**
     * @param  array<string, array{id: int, installed_at: string|null}>  $municipalities
     */
    private function importYear(
        DataSource $source,
        IndicatorVersion $indicatorVersion,
        array $municipalities,
        PopulationSourceDefinition $definition,
    ): ImportSummary {
        $artifact = $this->fetcher->fetch($definition);
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'ibge_population_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'reference_year' => $definition->year,
                'dataset' => $definition->dataset,
                'source_url' => $definition->url,
                'artifact_checksum' => $stored->checksum,
            ],
        ]);

        try {
            $records = [];
            $rejectedRows = 0;

            foreach ($this->parser->records($artifact->contents, $definition) as $rowNumber => $record) {
                try {
                    $code = $record['municipality_code'];

                    if (isset($records[$code])) {
                        throw new RuntimeException("Duplicate municipality code in official source: {$code}");
                    }

                    if (! isset($municipalities[$code])) {
                        throw new RuntimeException("Municipality code is absent from the registry: {$code}");
                    }

                    $availability = $this->parser->availabilityStatus(
                        rawValue: $record['raw_value'],
                        installedAt: $municipalities[$code]['installed_at'],
                        referenceYear: $definition->year,
                    );

                    $records[$code] = [
                        'municipality_id' => $municipalities[$code]['id'],
                        'value' => $availability === AvailabilityStatus::Available
                            ? $this->parser->value($record['raw_value'])
                            : null,
                        'availability_status' => $availability,
                        'source_marker' => $availability === AvailabilityStatus::Available ? null : $record['raw_value'],
                        'source_row' => $rowNumber,
                    ];
                } catch (Throwable $exception) {
                    $rejectedRows++;

                    if ($rejectedRows <= 100) {
                        ProcessingError::query()->create([
                            'processing_run_id' => $run->id,
                            'row_number' => $rowNumber,
                            'municipality_code' => $record['municipality_code'],
                            'indicator_slug' => 'population',
                            'code' => 'invalid_population_record',
                            'message' => $exception->getMessage(),
                            'payload' => $record,
                        ]);
                    }
                }
            }

            $acceptedRows = count($records);
            $availableRows = count(array_filter(
                $records,
                fn (array $record): bool => $record['availability_status'] === AvailabilityStatus::Available,
            ));

            if (
                $rejectedRows > 0
                || $acceptedRows !== $definition->expectedRecords
                || $availableRows !== $definition->expectedAvailableMunicipalities
            ) {
                throw new RuntimeException(
                    "Population coverage failed for {$definition->year}: expected {$definition->expectedRecords} records and {$definition->expectedAvailableMunicipalities} available municipalities; accepted {$acceptedRows}, available {$availableRows}, rejected {$rejectedRows}.",
                );
            }

            $createdRows = DB::transaction(function () use (
                $source,
                $indicatorVersion,
                $definition,
                $artifact,
                $stored,
                $run,
                $records,
            ): int {
                $release = SourceRelease::query()->firstOrCreate(
                    [
                        'data_source_id' => $source->id,
                        'reference_year' => $definition->year,
                        'version' => 'official-'.substr($stored->checksum, 0, 16),
                    ],
                    [
                        'status' => ReleaseStatus::Final,
                        'published_at' => $definition->publishedAt->format('Y-m-d'),
                        'collected_at' => now()->toDateString(),
                        'source_url' => $artifact->sourceUrl,
                        'artifact_disk' => $stored->disk,
                        'artifact_path' => $stored->path,
                        'checksum_sha256' => $stored->checksum,
                        'mime_type' => $stored->mimeType,
                        'size_bytes' => $stored->sizeBytes,
                        'metadata' => $this->releaseMetadata($definition),
                    ],
                );
                $run->update(['source_release_id' => $release->id]);
                $createdRows = $this->insertObservations($records, $indicatorVersion, $release, $run, $definition);

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
                        ->where('reference_year', $definition->year)
                        ->where('id', '!=', $release->id)
                        ->whereNull('superseded_by_id')
                        ->update(['superseded_by_id' => $release->id]);
                }

                return $createdRows;
            });

            return new ImportSummary($acceptedRows, $acceptedRows, 0, $createdRows);
        } catch (Throwable $exception) {
            $run->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'input_rows' => count($records) + $rejectedRows,
                'accepted_rows' => count($records),
                'rejected_rows' => $rejectedRows,
                'error_summary' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, array{municipality_id: int, value: int|null, availability_status: AvailabilityStatus, source_marker: string|null, source_row: int}>  $records
     */
    private function insertObservations(
        array $records,
        IndicatorVersion $indicatorVersion,
        SourceRelease $release,
        ProcessingRun $run,
        PopulationSourceDefinition $definition,
    ): int {
        $createdRows = 0;
        $batch = [];
        $now = now();

        foreach ($records as $record) {
            $batch[] = [
                'observation_key' => hash('sha256', implode('|', [
                    $record['municipality_id'],
                    $indicatorVersion->id,
                    $release->id,
                    $definition->year,
                    '',
                    '',
                ])),
                'municipality_id' => $record['municipality_id'],
                'indicator_version_id' => $indicatorVersion->id,
                'source_release_id' => $release->id,
                'processing_run_id' => $run->id,
                'reference_year' => $definition->year,
                'value' => $record['value'],
                'availability_status' => $record['availability_status']->value,
                'quality_status' => QualityStatus::Accepted->value,
                'metadata' => json_encode([
                    ...$this->releaseMetadata($definition),
                    'source_row' => $record['source_row'],
                    'source_marker' => $record['source_marker'],
                ], JSON_THROW_ON_ERROR),
                'observed_at' => $now,
                'created_at' => $now,
            ];

            if (count($batch) === 500) {
                $createdRows += IndicatorObservation::query()->insertOrIgnore($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $createdRows += IndicatorObservation::query()->insertOrIgnore($batch);
        }

        return $createdRows;
    }

    /** @return array<string, string> */
    private function releaseMetadata(PopulationSourceDefinition $definition): array
    {
        return [
            'methodology' => $definition->methodology,
            'statistical_reference_date' => $definition->statisticalReferenceDate,
            'territorial_reference' => $definition->territorialReference,
            'dataset' => $definition->dataset,
        ];
    }

    private function definitionFor(int $year): PopulationSourceDefinition
    {
        $configuration = config("municipal_data.population.{$year}");

        if (! is_array($configuration)) {
            throw new RuntimeException("No official IBGE population source is configured for {$year}.");
        }

        return PopulationSourceDefinition::fromConfiguration($year, $configuration);
    }
}
