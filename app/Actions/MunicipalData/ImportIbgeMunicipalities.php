<?php

namespace App\Actions\MunicipalData;

use App\Enums\ProcessingStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\FederativeUnit;
use App\Models\Municipality;
use App\Models\MunicipalityIdentifier;
use App\Models\ProcessingError;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\MunicipalData\Fetchers\IbgeMunicipalityFetcher;
use App\MunicipalData\ImportSummary;
use Illuminate\Support\Str;
use Throwable;

class ImportIbgeMunicipalities
{
    public function __construct(
        private readonly IbgeMunicipalityFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
    ) {}

    public function execute(int $referenceYear): ImportSummary
    {
        $source = DataSource::query()->where('slug', 'ibge-localidades')->firstOrFail();
        $artifact = $this->fetcher->fetch($referenceYear);
        $stored = $this->artifactStore->fromFetched($source, $artifact);

        $release = SourceRelease::query()->firstOrCreate(
            [
                'data_source_id' => $source->id,
                'reference_year' => $referenceYear,
                'version' => 'snapshot-'.substr($stored['checksum'], 0, 12),
            ],
            [
                'status' => ReleaseStatus::Final,
                'published_at' => $artifact->publishedAt?->format('Y-m-d'),
                'collected_at' => now()->toDateString(),
                'source_url' => $artifact->sourceUrl,
                'artifact_disk' => $stored['disk'],
                'artifact_path' => $stored['path'],
                'checksum_sha256' => $stored['checksum'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
            ],
        );

        if ($release->wasRecentlyCreated) {
            SourceRelease::query()
                ->where('data_source_id', $source->id)
                ->where('reference_year', $referenceYear)
                ->whereKeyNot($release->id)
                ->whereNull('superseded_by_id')
                ->update(['superseded_by_id' => $release->id]);
        }

        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'source_release_id' => $release->id,
            'type' => 'municipality_registry_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => ['reference_year' => $referenceYear],
        ]);

        try {
            $records = json_decode($artifact->contents, true, flags: JSON_THROW_ON_ERROR);
            $inputRows = count($records);
            $acceptedRows = 0;

            foreach ($records as $index => $record) {
                try {
                    $ufAcronym = data_get($record, 'microrregiao.mesorregiao.UF.sigla')
                        ?? data_get($record, 'regiao-imediata.regiao-intermediaria.UF.sigla');
                    $unit = FederativeUnit::query()->where('acronym', $ufAcronym)->firstOrFail();
                    $code = str_pad((string) $record['id'], 7, '0', STR_PAD_LEFT);
                    $name = trim((string) $record['nome']);

                    $municipality = Municipality::query()->updateOrCreate(
                        ['ibge_code' => $code],
                        [
                            'federative_unit_id' => $unit->id,
                            'name' => $name,
                            'normalized_name' => Str::lower(Str::ascii($name)),
                            'is_active' => true,
                            'installed_at' => $code === '5101837' ? '2025-01-01' : null,
                        ],
                    );

                    MunicipalityIdentifier::query()->updateOrCreate(
                        ['scheme' => 'ibge', 'value' => $code],
                        ['municipality_id' => $municipality->id],
                    );
                    $acceptedRows++;
                } catch (Throwable $exception) {
                    ProcessingError::query()->create([
                        'processing_run_id' => $run->id,
                        'row_number' => $index + 1,
                        'municipality_code' => isset($record['id']) ? (string) $record['id'] : null,
                        'code' => 'invalid_municipality_record',
                        'message' => $exception->getMessage(),
                        'payload' => $record,
                    ]);
                }
            }

            $rejectedRows = $inputRows - $acceptedRows;
            $run->update([
                'status' => ProcessingStatus::Completed,
                'finished_at' => now(),
                'input_rows' => $inputRows,
                'accepted_rows' => $acceptedRows,
                'rejected_rows' => $rejectedRows,
            ]);

            return new ImportSummary($inputRows, $acceptedRows, $rejectedRows, $acceptedRows);
        } catch (Throwable $exception) {
            $run->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'error_summary' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
