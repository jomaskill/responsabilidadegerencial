<?php

use App\Enums\ProcessingStatus;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);

    Municipality::query()->create([
        'federative_unit_id' => FederativeUnit::query()->where('acronym', 'SP')->value('id'),
        'ibge_code' => '3550308',
        'name' => 'São Paulo',
        'normalized_name' => 'sao paulo',
        'is_active' => true,
    ]);

    foreach (range(2021, 2025) as $year) {
        config()->set("municipal_data.population.{$year}.expected_records", 1);
        config()->set("municipal_data.population.{$year}.expected_available_municipalities", 1);
    }
});

test('official IBGE population sources are imported with annual methodology metadata', function () {
    $values = [
        2021 => '12396372',
        2022 => '11451999',
        2024 => '11895578',
        2025 => '11904961',
    ];
    $fakes = [];

    foreach ($values as $year => $value) {
        $fakes[config("municipal_data.population.{$year}.url")] = Http::response([
            [
                'D1C' => 'Município (Código)',
                'V' => 'Valor',
            ],
            [
                'D1C' => '3550308',
                'D1N' => 'São Paulo (SP)',
                'D3C' => (string) $year,
                'V' => $value,
            ],
        ]);
    }

    $fakes[config('municipal_data.population.2023.url')] = Http::response(
        populationOdsFixture(),
        200,
        ['Content-Type' => 'application/vnd.oasis.opendocument.spreadsheet'],
    );
    Http::fake($fakes);

    $arguments = [
        'source' => 'ibge-populacao',
        '--from' => '2021',
        '--to' => '2025',
    ];

    $this->artisan('data:import', $arguments)->assertSuccessful();

    $observations = IndicatorObservation::query()
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'population'))
        ->orderBy('reference_year')
        ->get();

    expect($observations)->toHaveCount(5)
        ->and($observations->pluck('value', 'reference_year')->map(fn ($value) => (int) $value)->all())->toBe([
            2021 => 12_396_372,
            2022 => 11_451_999,
            2023 => 11_451_999,
            2024 => 11_895_578,
            2025 => 11_904_961,
        ])
        ->and($observations->firstWhere('reference_year', 2023)?->metadata['methodology'])
        ->toBe('official_tcu_population_reference')
        ->and($observations->firstWhere('reference_year', 2023)?->metadata['statistical_reference_date'])
        ->toBe('2022-07-31');

    expect(SourceRelease::query()->count())->toBe(5)
        ->and(ProcessingRun::query()->where('status', ProcessingStatus::Completed)->count())->toBe(5);

    SourceRelease::query()->each(function (SourceRelease $release): void {
        expect(Storage::disk('local')->exists($release->artifact_path))->toBeTrue();
    });

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(5);
    Http::assertSentCount(10);
});

function populationOdsFixture(): string
{
    $temporaryFile = tempnam(sys_get_temp_dir(), 'population-test-ods-');
    $archive = new ZipArchive;
    $archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString(
        'content.xml',
        file_get_contents(base_path('tests/Fixtures/ibge_population_2023_content.xml')),
    );
    $archive->close();
    $contents = file_get_contents($temporaryFile);
    unlink($temporaryFile);

    return $contents;
}
