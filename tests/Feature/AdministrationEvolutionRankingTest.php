<?php

use App\Actions\MunicipalRanking\CalculateAdministrationEvolutionRanking;
use App\Actions\PublicHome\BuildPublicHomeHighlights;
use App\DTO\MunicipalRanking\AdministrationEvolutionQueryData;
use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\Administration;
use App\Models\AdministrationOfficeHolder;
use App\Models\DataSource;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\Municipality;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::clear();
    $this->seed(DatabaseSeeder::class);
    $unit = FederativeUnit::query()->where('acronym', 'SP')->firstOrFail();
    $this->evolutionMunicipalities = collect([
        ['ibge_code' => '3501001', 'name' => 'Alpha'],
        ['ibge_code' => '3501002', 'name' => 'Beta'],
        ['ibge_code' => '3501003', 'name' => 'Gamma'],
    ])->map(fn (array $municipality) => Municipality::query()->create([
        'federative_unit_id' => $unit->id,
        'ibge_code' => $municipality['ibge_code'],
        'name' => $municipality['name'],
        'normalized_name' => mb_strtolower($municipality['name']),
        'is_active' => true,
    ]));
    $source = DataSource::query()->where('slug', 'manual-compiled')->firstOrFail();

    foreach ([2021, 2024] as $year) {
        $release = SourceRelease::query()->create([
            'data_source_id' => $source->id,
            'reference_year' => $year,
            'version' => "evolution-fixture-{$year}",
            'status' => ReleaseStatus::Final,
            'collected_at' => now()->toDateString(),
            'source_url' => "https://example.test/evolution/{$year}",
            'checksum_sha256' => str_repeat((string) ($year % 10), 64),
        ]);
        $this->evolutionRuns[$year] = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'source_release_id' => $release->id,
            'type' => 'evolution_fixture',
            'status' => ProcessingStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    $values = [
        2021 => [
            'population' => [10_000, 20_000, 30_000],
            'gdp_per_capita' => [300, 200, 100],
            'ideb_initial_years' => [9, 7, 5],
            'ideb_final_years' => [8, 6, 4],
            'homicide_rate' => [10, 20, 30],
        ],
        2024 => [
            'population' => [11_000, 21_000, 31_000],
            'gdp_per_capita' => [100, 200, 300],
            'ideb_initial_years' => [5, 7, 9],
            'ideb_final_years' => [4, 6, 8],
            'homicide_rate' => [30, 20, 10],
        ],
    ];

    foreach ($values as $year => $indicators) {
        foreach ($indicators as $slug => $municipalityValues) {
            foreach ($this->evolutionMunicipalities as $index => $municipality) {
                createEvolutionObservation(
                    $municipality,
                    $slug,
                    $year,
                    $municipalityValues[$index],
                    $this->evolutionRuns[$year],
                );
            }
        }
    }

    foreach ($this->evolutionMunicipalities as $index => $municipality) {
        $administration = Administration::query()->create([
            'municipality_id' => $municipality->id,
            'election_year' => 2020,
            'term_start' => '2021-01-01',
            'term_end' => '2024-12-31',
            'status' => 'completed',
        ]);
        AdministrationOfficeHolder::query()->create([
            'administration_id' => $administration->id,
            'external_identifier' => "candidate-{$index}",
            'name' => "Prefeito {$municipality->name}",
            'role' => 'mayor',
            'party_acronym' => 'TST',
            'started_at' => '2021-01-01',
            'ended_at' => '2024-12-31',
            'source_url' => 'https://tse.example.test',
        ]);
    }
});

test('administration ranking measures relative evolution and uses competition positions', function () {
    $result = app(CalculateAdministrationEvolutionRanking::class)->execute(
        new AdministrationEvolutionQueryData(electionYear: 2020),
    );

    expect($result->rows)->toHaveCount(3)
        ->and($result->rows[0]['municipality']['name'])->toBe('Gamma')
        ->and($result->rows[0]['rank'])->toBe(1)
        ->and($result->rows[0]['evolution_score'])->toBe(100.0)
        ->and($result->rows[0]['evolution_summary'])->toBe([
            'improved' => 4,
            'declined' => 0,
            'unchanged' => 0,
            'not_comparable' => 0,
        ])
        ->and($result->rows[0])->not->toHaveKey('components')
        ->and($result->rows[1]['municipality']['name'])->toBe('Beta')
        ->and($result->rows[1]['evolution_score'])->toBe(0.0)
        ->and($result->rows[1]['evolution_summary'])->toBe([
            'improved' => 0,
            'declined' => 0,
            'unchanged' => 4,
            'not_comparable' => 0,
        ])
        ->and($result->rows[2]['municipality']['name'])->toBe('Alpha')
        ->and($result->rows[2]['evolution_score'])->toBe(-100.0)
        ->and($result->rows[2]['evolution_summary'])->toBe([
            'improved' => 0,
            'declined' => 4,
            'unchanged' => 0,
            'not_comparable' => 0,
        ])
        ->and($result->meta['ranking_available'])->toBeTrue()
        ->and($result->meta['advanced_indicators'])
        ->toContain('gdp_per_capita', 'ideb_initial_years', 'homicide_rate');
});

test('administration ranking hides an indicator for everyone when one endpoint is incomplete', function () {
    IndicatorObservation::query()
        ->where('municipality_id', $this->evolutionMunicipalities[2]->id)
        ->where('reference_year', 2024)
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'ideb_final_years'))
        ->delete();
    Cache::clear();

    $result = app(CalculateAdministrationEvolutionRanking::class)->execute(
        new AdministrationEvolutionQueryData(electionYear: 2020),
    );
    $gamma = collect($result->rows)->firstWhere('municipality.name', 'Gamma');

    expect($result->meta['advanced_indicators'])->not->toContain('ideb_final_years')
        ->and($gamma['evolution_summary'])->toBe([
            'improved' => 3,
            'declined' => 0,
            'unchanged' => 0,
            'not_comparable' => 0,
        ])
        ->and(collect($result->rows)->pluck('evolution_summary.not_comparable')->unique()->all())
        ->toBe([0]);
});

test('public home accepts a completed ranking with fewer than five administrations', function () {
    $highlights = app(BuildPublicHomeHighlights::class)->administrations();

    expect($highlights['selected']['rows'])->toHaveCount(3)
        ->and($highlights['selected']['rows'][0]['mayor']['name'])->toBe('Prefeito Gamma')
        ->and($highlights['selected']['rows'][0]['evolution_summary']['improved'])->toBe(4);
});

test('clicking a mayor reveals the indicator breakdown on home and full ranking', function () {
    $administrationId = Administration::query()
        ->where('election_year', 2020)
        ->whereHas('municipality', fn ($query) => $query->where('name', 'Gamma'))
        ->value('id');

    Livewire::test('pages::home')
        ->call('showAdministrationBreakdown', $administrationId)
        ->assertSet('selectedExplanation.administration.id', $administrationId)
        ->assertSee('O que mudou na gestão de Prefeito Gamma')
        ->assertSee('Melhorou')
        ->assertSee('Percentil');

    Livewire::withQueryParams(['electionYear' => 2020])
        ->test('pages::mayors')
        ->assertSee('ver o que mudou')
        ->call('showExplanation', $administrationId)
        ->assertSet('selectedExplanation.administration.id', $administrationId)
        ->assertSee('O que mudou na gestão de Prefeito Gamma')
        ->assertSee('Contribuição');
});

test('the 2017 to 2020 administration mandate uses its historical endpoints', function () {
    $source = DataSource::query()->where('slug', 'manual-compiled')->firstOrFail();

    foreach ([2017, 2020] as $year) {
        $release = SourceRelease::query()->create([
            'data_source_id' => $source->id,
            'reference_year' => $year,
            'version' => "historical-evolution-fixture-{$year}",
            'status' => ReleaseStatus::Final,
            'collected_at' => now()->toDateString(),
            'source_url' => "https://example.test/historical-evolution/{$year}",
            'checksum_sha256' => str_repeat((string) ($year % 10), 64),
        ]);
        $runs[$year] = ProcessingRun::query()->create([
            'data_source_id' => $source->id,
            'source_release_id' => $release->id,
            'type' => 'historical_evolution_fixture',
            'status' => ProcessingStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    foreach ($this->evolutionMunicipalities as $index => $municipality) {
        foreach (['gdp_per_capita', 'ideb_initial_years', 'ideb_final_years', 'homicide_rate'] as $slug) {
            createEvolutionObservation($municipality, $slug, 2017, 100 - ($index * 25), $runs[2017]);
            createEvolutionObservation($municipality, $slug, 2020, 50 + ($index * 25), $runs[2020]);
        }

        createEvolutionObservation($municipality, 'population', 2020, 10_000 + ($index * 1_000), $runs[2020]);

        $administration = Administration::query()->create([
            'municipality_id' => $municipality->id,
            'election_year' => 2016,
            'term_start' => '2017-01-01',
            'term_end' => '2020-12-31',
            'status' => 'completed',
        ]);
        AdministrationOfficeHolder::query()->create([
            'administration_id' => $administration->id,
            'external_identifier' => "historical-candidate-{$index}",
            'name' => "Gestor histórico {$municipality->name}",
            'role' => 'mayor',
            'party_acronym' => 'HST',
            'started_at' => '2017-01-01',
            'ended_at' => '2020-12-31',
        ]);
    }
    Cache::clear();

    $result = app(CalculateAdministrationEvolutionRanking::class)->execute(
        new AdministrationEvolutionQueryData(electionYear: 2016),
    );

    expect($result->meta['baseline_year'])->toBe(2017)
        ->and($result->meta['end_year'])->toBe(2020)
        ->and($result->meta['ranking_available'])->toBeTrue()
        ->and($result->rows)->toHaveCount(3);
});

test('current administrations wait until enough national source years advance', function () {
    foreach ($this->evolutionMunicipalities as $index => $municipality) {
        $administration = Administration::query()->create([
            'municipality_id' => $municipality->id,
            'election_year' => 2024,
            'term_start' => '2025-01-01',
            'term_end' => '2028-12-31',
            'status' => 'active',
        ]);
        AdministrationOfficeHolder::query()->create([
            'administration_id' => $administration->id,
            'external_identifier' => "current-candidate-{$index}",
            'name' => "Atual {$municipality->name}",
            'role' => 'mayor',
            'started_at' => '2025-01-01',
            'ended_at' => '2028-12-31',
        ]);
    }
    Cache::clear();

    $result = app(CalculateAdministrationEvolutionRanking::class)->execute(
        new AdministrationEvolutionQueryData(electionYear: 2024),
    );

    expect($result->meta['ranking_available'])->toBeFalse()
        ->and($result->meta['global_updated_weight_percent'])->toBe(0.0)
        ->and(collect($result->rows)->pluck('status')->unique()->all())
        ->toBe(['awaiting_new_data'])
        ->and(collect($result->rows)->pluck('evolution_summary')->unique()->all())
        ->toBe([null])
        ->and(collect($result->rows)->pluck('rank')->filter()->all())->toBe([]);
});

test('mayor ranking keeps components reserved for the detailed explanation', function () {
    $query = new AdministrationEvolutionQueryData(electionYear: 2020, perPage: 2);
    $ranking = app(CalculateAdministrationEvolutionRanking::class);
    $result = $ranking->execute($query);
    $explanation = $ranking->explanation($query, $result->rows[0]['administration']['id']);

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0])->not->toHaveKey('components')
        ->and($explanation)->toHaveKey('components')
        ->and($explanation['components'][0])->toHaveKeys(['baseline', 'end', 'contribution']);
});

function createEvolutionObservation(
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
        'observation_key' => hash('sha256', "evolution|{$municipality->id}|{$slug}|{$year}"),
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
