<?php

use App\Actions\PublicHome\BuildPublicHomeHighlights;
use App\Models\FederativeUnit;
use App\Models\Municipality;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::clear();
    $this->seed(DatabaseSeeder::class);
    $unit = FederativeUnit::query()->where('acronym', 'SP')->firstOrFail();
    $this->publicMunicipality = Municipality::query()->create([
        'federative_unit_id' => $unit->id,
        'ibge_code' => '3502001',
        'name' => 'Cidade Pública',
        'normalized_name' => 'cidade publica',
        'is_active' => true,
    ]);
});

test('home hides indicators until national coverage is complete', function () {
    $indicators = app(BuildPublicHomeHighlights::class)->indicators(2025);

    expect($indicators)->toBe([]);
});

test('public routes use the new civic navigation without a public dark mode control', function () {
    foreach ([
        '/',
        '/ranking',
        '/prefeitos',
        '/municipios',
        "/municipios/{$this->publicMunicipality->ibge_code}",
        '/metodologia',
        '/dados-abertos',
    ] as $path) {
        $this->get($path)->assertSuccessful();
    }

    $this->get('/')
        ->assertSee('Responsabilidade Gerencial')
        ->assertSeeInOrder([
            'Gestões que mais avançaram nos indicadores',
            'Encontre sua cidade',
            'Ranking consolidado dos municípios',
            'Rankings por indicador',
        ])
        ->assertSee('A associação temporal não comprova causalidade do prefeito.')
        ->assertSee('Ver ranking completo')
        ->assertSee('Entenda o cálculo')
        ->assertSee('Gestões de prefeitos')
        ->assertSee('2017–2020')
        ->assertSee('Dados e fontes')
        ->assertDontSee('Comparar')
        ->assertDontSee('API pública')
        ->assertDontSee('lidera o ranking consolidado')
        ->assertDontSee('Alternar modo claro e escuro');
});

test('comparison and public API routes are no longer available', function () {
    $this->get('/comparar')->assertNotFound();
    $this->get('/api/v1/rankings')->assertNotFound();
    $this->get('/api/v1/mayor-rankings')->assertNotFound();
    $this->get('/api/v1/transparency/sources')->assertNotFound();
    $this->get('/api/v1/exports/ranking.csv')->assertNotFound();
});

test('home exercise is shareable and municipality search redirects to the directory', function () {
    Livewire::withQueryParams(['year' => 2024, 'electionYear' => 2016])
        ->test('pages::home')
        ->assertSet('year', 2024)
        ->assertSet('electionYear', 2016)
        ->set('municipalitySearch', 'Cidade Pública')
        ->call('searchMunicipality')
        ->assertRedirect(route('public.municipalities', ['search' => 'Cidade Pública']));
});

test('mayor administrations expose both completed mandates', function () {
    Livewire::withQueryParams(['electionYear' => 2016])
        ->test('pages::mayors')
        ->assertSet('electionYear', 2016)
        ->assertSee('2017–2020')
        ->assertSee('2021–2024');
});

test('municipality directory filters by a shareable search term', function () {
    Livewire::withQueryParams(['search' => 'Cidade Pública'])
        ->test('pages::municipalities')
        ->assertSet('search', 'Cidade Pública')
        ->assertSee('Cidade Pública/SP')
        ->assertSee('3502001');
});
