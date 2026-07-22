<?php

namespace App\Actions\MunicipalData;

use App\Contracts\MunicipalData\TseElectionFetcher;
use App\DTO\MunicipalData\ImportSummary;
use App\DTO\MunicipalData\SourceArtifact;
use App\DTO\MunicipalData\StoredSourceArtifact;
use App\Enums\ProcessingStatus;
use App\Enums\ReleaseStatus;
use App\Models\Administration;
use App\Models\AdministrationOfficeHolder;
use App\Models\DataSource;
use App\Models\Municipality;
use App\Models\MunicipalityIdentifier;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\Support\MunicipalData\Parsers\TseElectionArchiveParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportTseAdministrations
{
    public function __construct(
        private readonly TseElectionFetcher $fetcher,
        private readonly StoreSourceArtifact $artifactStore,
        private readonly TseElectionArchiveParser $parser,
    ) {}

    public function execute(int $electionYear): ImportSummary
    {
        if (! in_array($electionYear, [2016, 2020, 2024], true)) {
            throw new RuntimeException('Only the 2016, 2020 and 2024 general municipal elections are supported.');
        }

        $mappingRelease = $this->importMunicipalityCodes();
        $source = DataSource::query()->where('slug', 'tse-candidatos')->firstOrFail();
        $artifact = $this->fetcher->candidates($electionYear);
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $mayors = $this->parser->electedMayors($artifact->contents, $electionYear);
        $run = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'type' => 'tse_elected_mayors_import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
            'parameters' => [
                'election_year' => $electionYear,
                'artifact_checksum' => $stored->checksum,
                'mapping_release_id' => $mappingRelease->id,
                'scope' => 'general_election_only',
            ],
        ]);

        try {
            $created = DB::transaction(function () use ($source, $artifact, $stored, $mayors, $electionYear, $run): int {
                $release = $this->release($source, $artifact, $stored, $electionYear, [
                    'office' => 'Prefeito',
                    'election_scope' => 'general',
                    'excludes' => ['substitutions', 'supplementary_elections'],
                ]);
                $run->update(['source_release_id' => $release->id]);
                $municipalities = MunicipalityIdentifier::query()
                    ->where('scheme', 'tse')
                    ->pluck('municipality_id', 'value');
                $created = 0;

                foreach ($mayors as $mayor) {
                    $municipalityId = $municipalities->get($mayor['tse_code']);

                    if ($municipalityId === null) {
                        throw new RuntimeException("TSE municipality code is not mapped to IBGE: {$mayor['tse_code']}");
                    }

                    $termStart = ($electionYear + 1).'-01-01';
                    $termEnd = ($electionYear + 4).'-12-31';
                    $administration = Administration::query()->firstOrNew([
                        'municipality_id' => $municipalityId,
                        'election_year' => $electionYear,
                    ]);
                    $administration->fill([
                        'term_start' => $termStart,
                        'term_end' => $termEnd,
                        'status' => now()->toDateString() > $termEnd ? 'completed' : 'active',
                    ])->save();
                    $holder = AdministrationOfficeHolder::query()
                        ->where('external_identifier', $mayor['external_identifier'])
                        ->first()
                        ?? AdministrationOfficeHolder::query()
                            ->where('administration_id', $administration->id)
                            ->where('role', 'mayor')
                            ->first()
                        ?? new AdministrationOfficeHolder;
                    $isNewHolder = ! $holder->exists;
                    $holder->fill([
                        'administration_id' => $administration->id,
                        'source_release_id' => $release->id,
                        'external_identifier' => $mayor['external_identifier'],
                        'name' => $mayor['name'],
                        'role' => 'mayor',
                        'party_acronym' => $mayor['party_acronym'],
                        'started_at' => $termStart,
                        'ended_at' => $termEnd,
                        'source_url' => $artifact->sourceUrl,
                    ])->save();
                    $created += $isNewHolder ? 1 : 0;
                }

                $run->update([
                    'status' => ProcessingStatus::Completed,
                    'finished_at' => now(),
                    'input_rows' => count($mayors),
                    'accepted_rows' => count($mayors),
                    'rejected_rows' => 0,
                ]);

                return $created;
            });

            return new ImportSummary(count($mayors), count($mayors), 0, $created);
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

    private function importMunicipalityCodes(): SourceRelease
    {
        $source = DataSource::query()->where('slug', 'tse-municipality-codes')->firstOrFail();
        $artifact = $this->fetcher->municipalityCodes();
        $stored = $this->artifactStore->fromFetched($source, $artifact);
        $mappings = $this->parser->municipalityCodes($artifact->contents);
        $municipalities = Municipality::query()->pluck('id', 'ibge_code');

        return DB::transaction(function () use ($source, $artifact, $stored, $mappings, $municipalities): SourceRelease {
            $release = $this->release($source, $artifact, $stored, 2025, ['scheme' => 'tse_ibge']);

            foreach ($mappings as $mapping) {
                $municipalityId = $municipalities->get($mapping['ibge_code']);

                if ($municipalityId === null) {
                    continue;
                }

                MunicipalityIdentifier::query()->updateOrCreate(
                    ['scheme' => 'tse', 'value' => $mapping['tse_code']],
                    ['municipality_id' => $municipalityId],
                );
            }

            return $release;
        });
    }

    /** @param array<string, mixed> $metadata */
    private function release(
        DataSource $source,
        SourceArtifact $artifact,
        StoredSourceArtifact $stored,
        int $referenceYear,
        array $metadata,
    ): SourceRelease {
        $release = SourceRelease::query()->firstOrCreate(
            [
                'data_source_id' => $source->id,
                'reference_year' => $referenceYear,
                'version' => 'official-'.substr($stored->checksum, 0, 16),
            ],
            [
                'status' => ReleaseStatus::Final,
                'published_at' => $artifact->publishedAt?->format('Y-m-d'),
                'collected_at' => now()->toDateString(),
                'source_url' => $artifact->sourceUrl,
                'artifact_disk' => $stored->disk,
                'artifact_path' => $stored->path,
                'checksum_sha256' => $stored->checksum,
                'mime_type' => $stored->mimeType,
                'size_bytes' => $stored->sizeBytes,
                'metadata' => $metadata,
            ],
        );

        if ($release->wasRecentlyCreated) {
            SourceRelease::query()
                ->where('data_source_id', $source->id)
                ->where('reference_year', $referenceYear)
                ->where('id', '!=', $release->id)
                ->whereNull('superseded_by_id')
                ->update(['superseded_by_id' => $release->id]);
        }

        return $release;
    }
}
