<?php

namespace App\Actions\MunicipalData;

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
use App\MunicipalData\HomicideFetcher;
use App\MunicipalData\HomicideSourceDefinition;
use App\MunicipalData\ImportSummary;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class ImportDatasusHomicides
{
    public function __construct(
        private readonly HomicideFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
    ) {}

    public function execute(int $fromYear, int $toYear): ImportSummary
    {
        if ($fromYear > $toYear) {
            throw new RuntimeException('The initial year must be less than or equal to the final year.');
        }

        $source = DataSource::query()->where('slug', 'datasus-sim')->firstOrFail();
        $indicatorVersion = IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', 'homicide_count'))
            ->latest('version')
            ->firstOrFail();
        $totalInput = 0;
        $totalAccepted = 0;
        $totalCreated = 0;

        foreach (range($fromYear, $toYear) as $year) {
            $definition = $this->definitionFor($year);

            if ($definition === null) {
                continue;
            }

            $summary = $this->importYear($source, $indicatorVersion, $definition);
            $totalInput += $summary->inputRows;
            $totalAccepted += $summary->acceptedRows;
            $totalCreated += $summary->createdRows;
        }

        return new ImportSummary($totalInput, $totalAccepted, 0, $totalCreated);
    }

    private function importYear(
        DataSource $source,
        IndicatorVersion $indicatorVersion,
        HomicideSourceDefinition $definition,
    ): ImportSummary {
        $municipalities = $this->municipalityRegistry($definition->year);

        if (count($municipalities) !== $definition->expectedMunicipalities) {
            throw new RuntimeException("Municipality coverage failed for {$definition->year}: expected {$definition->expectedMunicipalities}, found ".count($municipalities).'.');
        }

        $artifact = $this->fetcher->fetch($definition);
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'datasus_homicide_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'reference_year' => $definition->year,
                'definition' => $definition->definition,
                'source_file' => $definition->file,
                'artifact_checksum' => $stored['checksum'],
            ],
        ]);

        try {
            $parsed = $this->parseTabnet($artifact->contents);
            $records = [];

            foreach ($municipalities as $sixDigitCode => $municipalityId) {
                $records[$sixDigitCode] = [
                    'municipality_id' => $municipalityId,
                    'value' => 0,
                ];
            }

            foreach ($parsed['counts'] as $sixDigitCode => $count) {
                if (! isset($records[$sixDigitCode])) {
                    ProcessingError::query()->create([
                        'processing_run_id' => $run->id,
                        'municipality_code' => $sixDigitCode,
                        'indicator_slug' => 'homicide_count',
                        'code' => 'unknown_municipality',
                        'message' => "DATASUS municipality is absent from the registry: {$sixDigitCode}",
                        'payload' => ['value' => $count],
                    ]);

                    throw new RuntimeException("DATASUS municipality is absent from the registry: {$sixDigitCode}");
                }

                $records[$sixDigitCode]['value'] = $count;
            }

            $createdRows = DB::transaction(function () use (
                $source,
                $indicatorVersion,
                $definition,
                $artifact,
                $stored,
                $run,
                $records,
                $parsed,
            ): int {
                $release = SourceRelease::query()->firstOrCreate(
                    [
                        'data_source_id' => $source->id,
                        'reference_year' => $definition->year,
                        'version' => 'official-'.substr($stored['checksum'], 0, 16),
                    ],
                    [
                        'status' => ReleaseStatus::Final,
                        'published_at' => $definition->publishedAt->format('Y-m-d'),
                        'collected_at' => now()->toDateString(),
                        'source_url' => $artifact->sourceUrl,
                        'artifact_disk' => $stored['disk'],
                        'artifact_path' => $stored['path'],
                        'checksum_sha256' => $stored['checksum'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'metadata' => $this->releaseMetadata($definition, $parsed['national_total']),
                    ],
                );
                $run->update(['source_release_id' => $release->id]);
                $createdRows = $this->insertObservations($records, $indicatorVersion, $release, $run, $definition);
                $run->update([
                    'status' => ProcessingStatus::Completed,
                    'finished_at' => now(),
                    'input_rows' => $parsed['source_rows'],
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

            return new ImportSummary($parsed['source_rows'], count($records), 0, $createdRows);
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
     * @param  array<int, array{municipality_id: int, value: int}>  $records
     *
     * @throws JsonException
     */
    private function insertObservations(
        array $records,
        IndicatorVersion $indicatorVersion,
        SourceRelease $release,
        ProcessingRun $run,
        HomicideSourceDefinition $definition,
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
                'availability_status' => AvailabilityStatus::Available->value,
                'quality_status' => QualityStatus::Accepted->value,
                'metadata' => json_encode($this->releaseMetadata($definition), JSON_THROW_ON_ERROR),
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

    /** @return array{counts: array<int, int>, national_total: int, source_rows: int} */
    private function parseTabnet(string $contents): array
    {
        if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $contents, $match) !== 1) {
            throw new RuntimeException('The DATASUS response does not contain the expected table.');
        }

        $lines = preg_split('/\R/', html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'ISO-8859-1'));
        $counts = [];
        $reportedTotal = null;
        $summedTotal = 0;
        $sourceRows = 0;

        foreach ($lines ?: [] as $line) {
            $fields = str_getcsv(trim($line), ';', '"', '');

            if (count($fields) < 2) {
                continue;
            }

            $label = trim($fields[0]);
            $digits = preg_replace('/\D/', '', $fields[1]);

            if ($digits === null || $digits === '') {
                continue;
            }

            $value = (int) $digits;

            if (strcasecmp($label, 'Total') === 0) {
                $reportedTotal = $value;

                continue;
            }

            $sourceRows++;
            $summedTotal += $value;

            if (preg_match('/^\s*(\d{6})\s+/', $label, $codeMatch) === 1) {
                $counts[(int) $codeMatch[1]] = $value;
            }
        }

        if ($reportedTotal === null || $reportedTotal !== $summedTotal) {
            throw new RuntimeException("DATASUS total validation failed: reported {$reportedTotal}, summed {$summedTotal}.");
        }

        return [
            'counts' => $counts,
            'national_total' => $reportedTotal,
            'source_rows' => $sourceRows,
        ];
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

            $municipalities[(int) substr((string) $municipality->ibge_code, 0, 6)] = (int) $municipality->id;
        }

        return $municipalities;
    }

    /** @return array<string, int|string> */
    private function releaseMetadata(HomicideSourceDefinition $definition, ?int $nationalTotal = null): array
    {
        return array_filter([
            'definition' => $definition->definition,
            'methodology_url' => $definition->methodologyUrl,
            'geography' => 'municipality_of_residence',
            'source_file' => $definition->file,
            'national_total' => $nationalTotal,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function definitionFor(int $year): ?HomicideSourceDefinition
    {
        $configuration = config("municipal_data.homicides.years.{$year}");

        return is_array($configuration)
            ? HomicideSourceDefinition::fromConfiguration($year, $configuration)
            : null;
    }
}
