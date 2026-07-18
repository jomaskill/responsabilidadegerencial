<?php

use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
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

    foreach (range(2021, 2023) as $year) {
        config()->set("municipal_data.gdp.years.{$year}.expected_municipalities", 1);
    }
});

test('official IBGE GDP archive imports nominal and official per capita values', function () {
    Http::fake([
        config('municipal_data.gdp.url') => Http::response(
            gdpArchiveFixture(),
            200,
            ['Content-Type' => 'application/zip'],
        ),
    ]);

    $arguments = [
        'source' => 'ibge-pib-municipios',
        '--from' => '2021',
        '--to' => '2025',
    ];

    $this->artisan('data:import', $arguments)
        ->expectsOutputToContain('ainda não publicou o PIB municipal')
        ->assertSuccessful();

    $observations = IndicatorObservation::query()
        ->with('indicatorVersion.indicator')
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->whereIn('slug', ['gdp_nominal', 'gdp_per_capita']))
        ->get()
        ->keyBy(fn (IndicatorObservation $observation): string => $observation->indicatorVersion->indicator->slug.':'.$observation->reference_year);

    expect($observations)->toHaveCount(6)
        ->and((int) $observations['gdp_nominal:2021']->value)->toBe(828_980_746_586)
        ->and((float) $observations['gdp_per_capita:2021']->value)->toBe(66_872.85)
        ->and((int) $observations['gdp_nominal:2023']->value)->toBe(1_066_825_104_983)
        ->and((float) $observations['gdp_per_capita:2023']->value)->toBe(93_156.23)
        ->and($observations['gdp_nominal:2023']->metadata['price_basis'])->toBe('current_prices');

    expect(SourceRelease::query()->count())->toBe(3);

    SourceRelease::query()->each(function (SourceRelease $release): void {
        expect(Storage::disk('local')->exists($release->artifact_path))->toBeTrue();
    });

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(6);
    Http::assertSentCount(2);
});

function gdpArchiveFixture(): string
{
    $rows = [
        gdpFixedWidthRow(2021, '3550308', '828980746.586', '66872.85'),
        gdpFixedWidthRow(2022, '3550308', '945946482.808', '82606.43'),
        gdpFixedWidthRow(2023, '3550308', '1066825104.983', '93156.23'),
    ];
    $temporaryFile = tempnam(sys_get_temp_dir(), 'gdp-test-');
    $archive = new ZipArchive;
    $archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString((string) config('municipal_data.gdp.entry'), implode("\n", $rows));
    $archive->close();
    $contents = file_get_contents($temporaryFile);
    unlink($temporaryFile);

    return $contents;
}

function gdpFixedWidthRow(int $year, string $municipalityCode, string $gdp, string $perCapita): string
{
    $line = str_repeat(' ', 1256);
    $line = substr_replace($line, (string) $year, 0, 4);
    $line = substr_replace($line, $municipalityCode, 46, 7);
    $line = substr_replace($line, str_pad($gdp, 19, ' ', STR_PAD_LEFT), 934, 19);

    return substr_replace($line, str_pad($perCapita, 19, ' ', STR_PAD_LEFT), 953, 19);
}
