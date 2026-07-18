<?php

use App\Enums\ReleaseStatus;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\SourceRelease;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);

    foreach ([
        ['3509502', 'Campinas'],
        ['3548500', 'Santos'],
        ['3550308', 'São Paulo'],
    ] as [$code, $name]) {
        Municipality::query()->create([
            'federative_unit_id' => FederativeUnit::query()->where('acronym', 'SP')->value('id'),
            'ibge_code' => $code,
            'name' => $name,
            'normalized_name' => str($name)->ascii()->lower()->toString(),
            'is_active' => true,
        ]);
    }

    config()->set('municipal_data.homicides.years.2024.expected_municipalities', 3);
});

test('official DATASUS homicide counts include municipal zeroes and skip unavailable 2025', function () {
    Http::fake([
        config('municipal_data.homicides.url') => Http::response(<<<'HTML'
            <html><body><pre>
            "Município";"Óbitos p/Residência"
            " MUNICIPIO IGNORADO - RO";1
            "350950 CAMPINAS";10
            "355030 SAO PAULO";279
            "Total";290
            </pre></body></html>
            HTML),
    ]);

    $this->artisan('data:import', [
        'source' => 'datasus-sim',
        '--from' => '2024',
        '--to' => '2025',
    ])->expectsOutputToContain('não publicou dados municipais')->assertSuccessful();

    $observations = IndicatorObservation::query()
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'homicide_count'))
        ->with('municipality')
        ->get();

    expect($observations)->toHaveCount(3)
        ->and((int) $observations->firstWhere('municipality.name', 'São Paulo')?->value)->toBe(279)
        ->and((int) $observations->firstWhere('municipality.name', 'Campinas')?->value)->toBe(10)
        ->and((int) $observations->firstWhere('municipality.name', 'Santos')?->value)->toBe(0)
        ->and($observations->pluck('reference_year')->unique()->all())->toBe([2024]);

    $release = SourceRelease::query()->sole();

    expect($release->status)->toBe(ReleaseStatus::Final)
        ->and($release->metadata['national_total'])->toBe(290)
        ->and($release->metadata['geography'])->toBe('municipality_of_residence')
        ->and(Storage::disk('local')->exists($release->artifact_path))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->body(), 'Arquivos=obtbr24.dbf')
            && str_contains($request->body(), 'SCausa_-_CID-BR-10=141')
            && str_contains($request->body(), 'SCausa_-_CID-BR-10=143');
    });
});
