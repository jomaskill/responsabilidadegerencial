<?php

use App\Actions\MunicipalData\ImportTseAdministrations;
use App\Actions\MunicipalData\ReportMunicipalDataCoverage;
use App\Models\AdministrationOfficeHolder;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\MunicipalityIdentifier;
use App\Models\ProcessingError;
use App\Models\SourceRelease;
use App\Support\MunicipalData\Parsers\TseElectionArchiveParser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);

    Municipality::query()->create([
        'federative_unit_id' => FederativeUnit::query()->where('acronym', 'SP')->value('id'),
        'ibge_code' => '3550308',
        'name' => 'São Paulo',
        'normalized_name' => 'sao paulo',
        'is_active' => true,
    ]);
});

test('canonical csv import preserves its artifact and rejects invalid rows', function () {
    $arguments = [
        'source' => 'manual-compiled',
        '--from' => '2024',
        '--to' => '2024',
        '--file' => base_path('tests/Fixtures/municipal_observations.csv'),
        '--status' => 'final',
        '--release-version' => 'fixture-v1',
        '--source-url' => 'https://example.test/official-release',
    ];

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(2)
        ->and(ProcessingError::query()->count())->toBe(1);

    $populationCoverage = collect(app(ReportMunicipalDataCoverage::class)->execute(2024))
        ->firstWhere('indicator', 'population');

    expect($populationCoverage['coverage_percent'])->toBe(100.0);

    $release = SourceRelease::query()->sole();

    expect($release->checksum_sha256)->toHaveLength(64)
        ->and($release->artifact_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($release->artifact_path))->toBeTrue();

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(2);
});

test('ibge municipality registry can be fetched without making real calls in tests', function () {
    Http::fake([
        'servicodados.ibge.gov.br/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/ibge_municipalities.json')),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $this->artisan('data:import', [
        'source' => 'ibge-localidades',
        '--to' => '2025',
    ])->assertSuccessful();

    expect(Municipality::query()->where('ibge_code', '3550308')->count())->toBe(1)
        ->and(SourceRelease::query()->where('reference_year', 2025)->count())->toBe(1);

    expect(Municipality::query()->where('ibge_code', '5101837')->value('installed_at'))
        ->not->toBeNull();

    Http::assertSentCount(1);
});

test('published observations cannot be edited or deleted', function () {
    $this->artisan('data:import', [
        'source' => 'manual-compiled',
        '--from' => '2024',
        '--to' => '2024',
        '--file' => base_path('tests/Fixtures/municipal_observations.csv'),
        '--release-version' => 'fixture-v1',
    ])->assertSuccessful();

    $observation = IndicatorObservation::query()->firstOrFail();

    expect(fn () => $observation->update(['value' => 1]))
        ->toThrow(RuntimeException::class, 'immutable');

    expect(fn () => $observation->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});

test('tse administrations import is auditable mapped and idempotent', function () {
    $mapping = tseZipFixture(
        'municipio_tse_ibge.csv',
        "CD_MUNICIPIO_TSE;CD_MUNICIPIO_IBGE\n71072;3550308\n",
    );
    $candidates = tseZipFixture(
        'consulta_cand_2024_BRASIL.csv',
        implode("\n", [
            'ANO_ELEICAO;NM_TIPO_ELEICAO;SG_UE;DS_CARGO;DS_SIT_TOT_TURNO;SQ_CANDIDATO;NM_URNA_CANDIDATO;NM_CANDIDATO;SG_PARTIDO',
            '2024;ELEIÇÃO ORDINÁRIA;71072;PREFEITO;ELEITO;250001234567;PREFEITA TESTE;NOME COMPLETO;ABC',
            '2024;ELEIÇÃO ORDINÁRIA;71072;VEREADOR;ELEITO;250009999999;OUTRO;OUTRO;XYZ',
            '2024;ELEIÇÃO SUPLEMENTAR;71072;PREFEITO;ELEITO;250008888888;SUPLEMENTAR;SUPLEMENTAR;XYZ',
        ])."\n",
    );
    $revisedCandidates = tseZipFixture(
        'consulta_cand_2024_BRASIL.csv',
        implode("\n", [
            'ANO_ELEICAO;NM_TIPO_ELEICAO;SG_UE;DS_CARGO;DS_SIT_TOT_TURNO;SQ_CANDIDATO;NM_URNA_CANDIDATO;NM_CANDIDATO;SG_PARTIDO',
            '2024;ELEIÇÃO ORDINÁRIA;71072;PREFEITO;ELEITO;250001234567;PREFEITA REVISADA;NOME COMPLETO;DEF',
        ])."\n",
    );

    Http::fake([
        '*municipio_tse_ibge.zip' => Http::sequence()
            ->push($mapping, 200, ['Content-Type' => 'application/zip'])
            ->push($mapping, 200, ['Content-Type' => 'application/zip'])
            ->push($mapping, 200, ['Content-Type' => 'application/zip']),
        '*consulta_cand_2024.zip' => Http::sequence()
            ->push($candidates, 200, ['Content-Type' => 'application/zip'])
            ->push($candidates, 200, ['Content-Type' => 'application/zip'])
            ->push($revisedCandidates, 200, ['Content-Type' => 'application/zip']),
    ]);

    app(ImportTseAdministrations::class)->execute(2024);
    app(ImportTseAdministrations::class)->execute(2024);

    $holder = AdministrationOfficeHolder::query()->sole();

    expect(MunicipalityIdentifier::query()->where('scheme', 'tse')->value('value'))->toBe('71072')
        ->and($holder->name)->toBe('PREFEITA TESTE')
        ->and($holder->external_identifier)->toBe('250001234567')
        ->and($holder->sourceRelease?->checksum_sha256)->toHaveLength(64)
        ->and(SourceRelease::query()->count())->toBe(2);

    Http::assertSentCount(4);

    expect(app(TseElectionArchiveParser::class)->electedMayors($revisedCandidates, 2024)[0]['name'])
        ->toBe('PREFEITA REVISADA');

    app(ImportTseAdministrations::class)->execute(2024);

    expect(AdministrationOfficeHolder::query()->count())->toBe(1)
        ->and(AdministrationOfficeHolder::query()->value('name'))->toBe('PREFEITA REVISADA')
        ->and(SourceRelease::query()->count())->toBe(3)
        ->and(SourceRelease::query()->whereNotNull('superseded_by_id')->count())->toBe(1);
});

function tseZipFixture(string $entry, string $csv): string
{
    $path = tempnam(sys_get_temp_dir(), 'tse-test-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString($entry, $csv);
    $archive->close();
    $contents = file_get_contents($path);
    unlink($path);

    return $contents;
}
