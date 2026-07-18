<?php

use App\Actions\MunicipalData\ReportMunicipalDataCoverage;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\ProcessingError;
use App\Models\SourceRelease;
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
