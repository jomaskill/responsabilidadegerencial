<?php

use App\Actions\MunicipalRanking\CalculateMunicipalRanking;
use App\Actions\PublicHome\BuildPublicHomeHighlights;
use App\DTO\MunicipalRanking\RankingQueryData;
use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\Municipality;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use App\Support\MunicipalRanking\NationalIndicatorCoverage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::clear();
    $this->seed(DatabaseSeeder::class);

    $unit = FederativeUnit::query()->where('acronym', 'SP')->firstOrFail();
    $this->municipalities = collect([
        ['ibge_code' => '3500001', 'name' => 'Alpha'],
        ['ibge_code' => '3500002', 'name' => 'Beta'],
        ['ibge_code' => '3500003', 'name' => 'Gamma'],
    ])->map(fn (array $municipality) => Municipality::query()->create([
        'federative_unit_id' => $unit->id,
        'ibge_code' => $municipality['ibge_code'],
        'name' => $municipality['name'],
        'normalized_name' => mb_strtolower($municipality['name']),
        'is_active' => true,
    ]));

    $source = DataSource::query()->where('slug', 'manual-compiled')->firstOrFail();

    foreach ([2024, 2025] as $year) {
        $release = SourceRelease::query()->create([
            'data_source_id' => $source->id,
            'reference_year' => $year,
            'version' => "ranking-fixture-{$year}",
            'status' => ReleaseStatus::Final,
            'collected_at' => now()->toDateString(),
            'source_url' => "https://example.test/{$year}",
            'checksum_sha256' => str_repeat((string) ($year % 10), 64),
        ]);
        $this->runs[$year] = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'source_release_id' => $release->id,
            'type' => 'ranking_fixture',
            'status' => ProcessingStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    $values = [
        'population' => [10_000, 20_000, 30_000],
        'gdp_per_capita' => [100, 200, 300],
        'homicide_rate' => [30, 20, 10],
    ];

    foreach ($values as $slug => $municipalityValues) {
        foreach ($this->municipalities as $index => $municipality) {
            createRankingObservation($municipality, $slug, 2024, $municipalityValues[$index], $this->runs[2024]);
        }
    }

    createRankingObservation(
        $this->municipalities[0],
        'gdp_per_capita',
        2025,
        10_000,
        $this->runs[2025],
    );
});

test('ranking uses latest non-future data, directions and competition positions', function () {
    $result = app(CalculateMunicipalRanking::class)->execute(new RankingQueryData(
        year: 2024,
        weights: ['gdp_per_capita' => 50, 'homicide_rate' => 50],
    ));

    expect($result->rows)->toHaveCount(3)
        ->and($result->rows[0]['municipality']['name'])->toBe('Gamma')
        ->and($result->rows[0]['rank'])->toBe(1)
        ->and($result->rows[0]['score'])->toBe(100.0)
        ->and($result->rows[1]['municipality']['name'])->toBe('Beta')
        ->and($result->rows[1]['score'])->toBe(50.0)
        ->and($result->rows[2]['municipality']['name'])->toBe('Alpha')
        ->and($result->rows[2]['score'])->toBe(0.0)
        ->and($result->meta['effective_years']['gdp_per_capita'])->toBe(2024);
});

test('population filters redefine the comparison group', function () {
    $result = app(CalculateMunicipalRanking::class)->execute(new RankingQueryData(
        year: 2024,
        populationMin: 20_000,
        weights: ['gdp_per_capita' => 100],
    ));

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0]['municipality']['name'])->toBe('Gamma')
        ->and($result->rows[0]['score'])->toBe(100.0)
        ->and($result->rows[1]['municipality']['name'])->toBe('Beta')
        ->and($result->rows[1]['score'])->toBe(0.0);
});

test('an incomplete indicator is hidden nationally instead of penalizing one municipality', function () {
    IndicatorObservation::query()
        ->where('municipality_id', $this->municipalities[1]->id)
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'homicide_rate'))
        ->where('reference_year', 2024)
        ->update(['quality_status' => QualityStatus::Rejected]);
    Cache::clear();

    $result = app(CalculateMunicipalRanking::class)->execute(new RankingQueryData(
        year: 2024,
        weights: ['gdp_per_capita' => 50, 'homicide_rate' => 50],
    ));
    $beta = collect($result->rows)->firstWhere('municipality.name', 'Beta');

    expect($result->meta['weights'])->toBe(['gdp_per_capita' => 100.0])
        ->and($beta['status'])->toBe('ranked')
        ->and($beta['score'])->toBe(50.0)
        ->and($beta['coverage_percent'])->toBe(100.0);
});

test('globally unavailable indicators leave the profile without penalizing municipalities', function () {
    IndicatorObservation::query()
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'homicide_rate'))
        ->update(['availability_status' => AvailabilityStatus::Suppressed]);
    Cache::clear();

    $result = app(CalculateMunicipalRanking::class)->execute(new RankingQueryData(
        year: 2024,
        weights: ['gdp_per_capita' => 50, 'homicide_rate' => 50],
    ));

    expect($result->meta['weights'])->toBe(['gdp_per_capita' => 100.0])
        ->and(collect($result->rows)->pluck('status')->unique()->all())->toBe(['ranked']);
});

test('provisional values are usable while a suppressed indicator is hidden for everyone', function () {
    IndicatorObservation::query()
        ->where('municipality_id', $this->municipalities[2]->id)
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'gdp_per_capita'))
        ->where('reference_year', 2024)
        ->update(['availability_status' => AvailabilityStatus::Provisional]);
    IndicatorObservation::query()
        ->where('municipality_id', $this->municipalities[1]->id)
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'homicide_rate'))
        ->where('reference_year', 2024)
        ->update(['availability_status' => AvailabilityStatus::Suppressed]);
    Cache::clear();

    $result = app(CalculateMunicipalRanking::class)->execute(new RankingQueryData(
        year: 2024,
        weights: ['gdp_per_capita' => 50, 'homicide_rate' => 50],
    ));
    $beta = collect($result->rows)->firstWhere('municipality.name', 'Beta');
    $gamma = collect($result->rows)->firstWhere('municipality.name', 'Gamma');

    expect($result->meta['weights'])->toBe(['gdp_per_capita' => 100.0])
        ->and($beta['status'])->toBe('ranked')
        ->and($beta['coverage_percent'])->toBe(100.0)
        ->and($gamma['status'])->toBe('ranked');
});

test('national publication gate keeps complete homicide and census sewer data only', function () {
    foreach ($this->municipalities as $municipality) {
        createRankingObservation(
            $municipality,
            'sewer_census',
            2024,
            80,
            $this->runs[2024],
        );
    }

    foreach ($this->municipalities->take(2) as $municipality) {
        createRankingObservation(
            $municipality,
            'sewer_service_coverage',
            2024,
            75,
            $this->runs[2024],
        );
        createRankingObservation(
            $municipality,
            'ideb_initial_years',
            2024,
            6,
            $this->runs[2024],
        );
    }

    $effectiveYears = app(NationalIndicatorCoverage::class)->completeEffectiveYears(2024, [
        'homicide_rate',
        'sewer_census',
        'sewer_service_coverage',
        'ideb_initial_years',
    ]);

    expect($effectiveYears)
        ->toBe([
            'homicide_rate' => 2024,
            'sewer_census' => 2024,
        ]);

    $publicIndicators = collect(app(BuildPublicHomeHighlights::class)->indicators(2024))
        ->pluck('slug');

    expect($publicIndicators)
        ->toContain('homicide_rate', 'sewer_census')
        ->not->toContain('ideb_initial_years', 'sewer_service_coverage');

    $this->get('/dados-abertos')
        ->assertSuccessful()
        ->assertSee('Taxa de homicídios')
        ->assertSee('Cobertura de esgoto — Censo')
        ->assertDontSee('IDEB — anos iniciais')
        ->assertDontSee('Cobertura do serviço de esgoto — SNIS/SINISA');
});

test('superseded releases are ignored and the latest earlier official year is used', function () {
    $source = DataSource::query()->where('slug', 'manual-compiled')->firstOrFail();
    $olderRelease = SourceRelease::query()->create([
        'data_source_id' => $source->id,
        'reference_year' => 2023,
        'version' => 'ranking-fixture-2023',
        'status' => ReleaseStatus::Final,
        'collected_at' => now()->toDateString(),
        'source_url' => 'https://example.test/2023',
        'checksum_sha256' => str_repeat('3', 64),
    ]);
    $olderRun = ProcessingRun::query()->create([
        'data_source_id' => $source->id,
        'source_release_id' => $olderRelease->id,
        'type' => 'ranking_fixture',
        'status' => ProcessingStatus::Completed,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    foreach (['population' => [9_000, 19_000, 29_000], 'gdp_per_capita' => [90, 190, 290], 'homicide_rate' => [31, 21, 11]] as $slug => $values) {
        foreach ($this->municipalities as $index => $municipality) {
            createRankingObservation($municipality, $slug, 2023, $values[$index], $olderRun);
        }
    }

    $replacement = SourceRelease::query()->create([
        'data_source_id' => $source->id,
        'reference_year' => 2024,
        'version' => 'ranking-fixture-2024-revised',
        'status' => ReleaseStatus::Revised,
        'collected_at' => now()->toDateString(),
        'source_url' => 'https://example.test/2024-revised',
        'checksum_sha256' => str_repeat('8', 64),
    ]);
    SourceRelease::query()->whereKey($this->runs[2024]->source_release_id)->update([
        'superseded_by_id' => $replacement->id,
    ]);
    Cache::clear();

    $query = new RankingQueryData(
        year: 2024,
        weights: ['gdp_per_capita' => 100],
    );
    $result = app(CalculateMunicipalRanking::class)->execute($query);
    $explanation = app(CalculateMunicipalRanking::class)->explanation($query, '3500003');

    expect($result->meta['effective_years']['gdp_per_capita'])->toBe(2023)
        ->and($result->rows[0])->not->toHaveKey('components')
        ->and($explanation['components'][0]['release']['checksum_sha256'])->toBe(str_repeat('3', 64));
});

test('public ranking keeps filters shareable and explains each score', function () {
    Livewire::withQueryParams([
        'year' => 2024,
        'uf' => 'SP',
        'weights' => ['gdp_per_capita' => 50, 'homicide_rate' => 50],
    ])->test('pages::ranking')
        ->assertSet('year', 2024)
        ->assertSet('uf', 'SP')
        ->assertSee('Gamma/SP')
        ->assertSee('Abrir composição')
        ->call('showExplanation', '3500003')
        ->assertSee('Composição de Gamma/SP')
        ->assertSee('Abrir fonte oficial')
        ->set('search', 'Beta')
        ->call('applyFilters')
        ->assertSee('Beta/SP')
        ->assertDontSee('Gamma/SP');
});

test('public municipality sheet defers evolution and loads one exercise at a time', function () {
    $this->get('/municipios/3500001')
        ->assertSuccessful()
        ->assertSee('Alpha/SP')
        ->assertSee('Evolução 2017–2025')
        ->assertSee('Carregando indicadores do município')
        ->assertDontSee('fonte ref. 2024');

    $component = Livewire::test('pages::municipality', ['ibgeCode' => '3500001']);
    $evolution = $component->instance()->evolution();

    expect($evolution['year'])->toBe(2025)
        ->and($evolution['score'])->not->toBeNull()
        ->and($evolution['rank'])->toBeInt()
        ->and($evolution['coverage'])->toBe(100.0)
        ->and($evolution['components'])
        ->not->toBeEmpty();

    $component
        ->set('year', 2024)
        ->assertSet('year', 2024);

    expect($component->instance()->evolution()['year'])->toBe(2024);
});

function createRankingObservation(
    Municipality $municipality,
    string $slug,
    int $year,
    float|int $value,
    ProcessingRun $run,
): IndicatorObservation {
    $version = IndicatorVersion::query()
        ->whereHas('indicator', fn ($query) => $query->where('slug', $slug))
        ->firstOrFail();

    return IndicatorObservation::query()->create([
        'observation_key' => hash('sha256', "{$municipality->id}|{$slug}|{$year}"),
        'municipality_id' => $municipality->id,
        'indicator_version_id' => $version->id,
        'source_release_id' => $run->source_release_id,
        'processing_run_id' => $run->id,
        'reference_year' => $year,
        'value' => $value,
        'availability_status' => AvailabilityStatus::Available,
        'quality_status' => QualityStatus::Accepted,
    ]);
}
