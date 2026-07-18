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

    Municipality::query()->create([
        'federative_unit_id' => FederativeUnit::query()->where('acronym', 'RO')->value('id'),
        'ibge_code' => '1101807',
        'name' => 'Vale do Paraíso',
        'normalized_name' => 'vale do paraiso',
        'is_active' => true,
    ]);

    config()->set('municipal_data.census_indicators.expected_municipalities', 2);
});

test('official Census percentages are combined into one traceable release', function () {
    $values = [
        'water_census' => '99.15',
        'sewer_census' => '95.23',
        'literacy_rate' => '97.42',
    ];
    $fakes = [];

    foreach ($values as $slug => $value) {
        $configuration = config("municipal_data.census_indicators.datasets.{$slug}");
        $fakes[$configuration['url']] = Http::response([
            ['D1C' => 'Município (Código)', 'V' => 'Valor'],
            ['D1C' => '3550308', 'D1N' => 'São Paulo (SP)', 'V' => $value],
            [
                'D1C' => '1101807',
                'D1N' => 'Vale do Paraíso (RO)',
                'V' => $slug === 'water_census' ? '-' : '0.04',
            ],
        ]);
    }

    Http::fake($fakes);

    $arguments = [
        'source' => 'ibge-censo-2022',
        '--from' => '2021',
        '--to' => '2025',
    ];

    $this->artisan('data:import', $arguments)->assertSuccessful();

    $observations = IndicatorObservation::query()
        ->with('indicatorVersion.indicator')
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->whereIn('slug', array_keys($values)))
        ->get()
        ->keyBy(fn (IndicatorObservation $observation): string => $observation->indicatorVersion->indicator->slug.':'.$observation->municipality_id);

    $saoPauloId = Municipality::query()->where('ibge_code', '3550308')->value('id');
    $valeDoParaisoId = Municipality::query()->where('ibge_code', '1101807')->value('id');

    expect($observations)->toHaveCount(6)
        ->and((float) $observations["water_census:{$saoPauloId}"]->value)->toBe(99.15)
        ->and((float) $observations["sewer_census:{$saoPauloId}"]->value)->toBe(95.23)
        ->and((float) $observations["literacy_rate:{$saoPauloId}"]->value)->toBe(97.42)
        ->and((float) $observations["water_census:{$valeDoParaisoId}"]->value)->toBe(0.0)
        ->and($observations["water_census:{$valeDoParaisoId}"]->metadata['source_marker'])->toBe('-')
        ->and($observations["water_census:{$saoPauloId}"]->reference_year)->toBe(2022)
        ->and($observations["water_census:{$saoPauloId}"]->metadata['table'])->toBe(6803);

    $release = SourceRelease::query()->sole();

    expect(Storage::disk('local')->exists($release->artifact_path))->toBeTrue()
        ->and($release->metadata['datasets'])->toHaveCount(3);

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(6)
        ->and(SourceRelease::query()->count())->toBe(1);
    Http::assertSentCount(6);
});
