<?php

use App\Actions\PublicHome\BuildPublicHomeHighlights;
use App\Actions\MunicipalRanking\CalculateAdministrationEvolutionRanking;
use App\DTO\MunicipalRanking\AdministrationEvolutionQueryData;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Responsabilidade Gerencial')] class extends Component {
    #[Url(history: true)]
    public int $electionYear = 2020;

    #[Url(history: true)]
    public int $year = 2025;

    public string $municipalitySearch = '';

    public ?array $selectedExplanation = null;

    public function updatedYear(): void
    {
        unset($this->consolidated, $this->indicators, $this->indicatorGroups, $this->freshness);
    }

    public function updatedElectionYear(): void
    {
        $this->selectedExplanation = null;
        unset($this->administrations);
    }

    public function showAdministrationBreakdown(int $administrationId): void
    {
        $this->selectedExplanation = app(CalculateAdministrationEvolutionRanking::class)
            ->explanation(
                new AdministrationEvolutionQueryData(electionYear: $this->electionYear, perPage: 5),
                $administrationId,
            );
    }

    public function searchMunicipality(): void
    {
        $this->redirectRoute('public.municipalities', [
            'search' => trim($this->municipalitySearch),
        ], navigate: true);
    }

    #[Computed]
    public function consolidated(): array
    {
        return app(BuildPublicHomeHighlights::class)->consolidated($this->year);
    }

    #[Computed]
    public function indicators(): array
    {
        return app(BuildPublicHomeHighlights::class)->indicators($this->year);
    }

    #[Computed]
    public function indicatorGroups(): array
    {
        return collect($this->indicators)
            ->groupBy('theme')
            ->map(fn (Collection $indicators): array => $indicators->values()->all())
            ->all();
    }

    #[Computed]
    public function administrations(): array
    {
        return app(BuildPublicHomeHighlights::class)->administrations($this->electionYear);
    }

    #[Computed]
    public function freshness(): array
    {
        return app(BuildPublicHomeHighlights::class)->freshness($this->year);
    }

    public function iconFor(string $slug): string
    {
        return match ($slug) {
            'gdp_per_capita' => 'banknotes',
            'gdp_real_growth' => 'arrow-trending-up',
            'ideb_initial_years' => 'book-open',
            'ideb_final_years' => 'academic-cap',
            'literacy_rate' => 'pencil-square',
            'water_census' => 'cloud',
            'sewer_census' => 'building-office-2',
            'water_service_coverage' => 'home-modern',
            'sewer_service_coverage' => 'wrench-screwdriver',
            'homicide_rate' => 'shield-exclamation',
            'homicide_rate_rolling_3y' => 'shield-check',
            default => 'chart-bar',
        };
    }

    public function themeLabel(string $theme): string
    {
        return match ($theme) {
            'economia' => 'Economia',
            'educacao' => 'Educação',
            'saneamento' => 'Saneamento',
            'seguranca' => 'Segurança',
            default => ucfirst($theme),
        };
    }

    public function mandateLabel(int $electionYear): string
    {
        return match ($electionYear) {
            2016 => '2017–2020',
            2020 => '2021–2024',
            2024 => '2025–2028',
            default => 'Mandato municipal',
        };
    }

    public function formatValue(?float $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($unit) {
            'BRL_por_habitante' => 'R$ '.number_format($value, 0, ',', '.'),
            'percentual' => number_format($value, 1, ',', '.').'%',
            'por_100_mil_habitantes' => number_format($value, 1, ',', '.'),
            'indice_0_10' => number_format($value, 1, ',', '.'),
            default => number_format($value, 1, ',', '.'),
        };
    }
}; ?>

<div class="grid gap-16 lg:gap-24">
    <section aria-labelledby="home-title" class="grid gap-8 border-b border-ink pb-12">
        <header class="grid gap-6 border-b border-rule pb-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div class="grid gap-5">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm font-semibold">
                    <span class="bg-brand px-3 py-1.5">Mandato concluído</span>
                    <label for="home-mandate" class="sr-only">Mandato em destaque</label>
                    <select id="home-mandate" wire:model.live="electionYear" class="border border-ink bg-white px-3 py-2 font-semibold">
                        <option value="2020">2021–2024</option>
                        <option value="2016">2017–2020</option>
                    </select>
                    <span class="text-muted">Brasil</span>
                </div>
                <div class="grid gap-4">
                    <h1 id="home-title" class="public-display max-w-[19ch] text-5xl sm:text-6xl lg:text-7xl">
                        Gestões que mais avançaram nos indicadores
                    </h1>
                    <p class="max-w-[68ch] text-lg leading-7 text-muted">
                        A classificação mede a mudança de posição relativa dos municípios durante o mandato.
                        A associação temporal não comprova causalidade do prefeito.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('public.mayors', ['electionYear' => $electionYear]) }}" class="inline-flex items-center gap-2 bg-brand px-5 py-3 font-bold text-ink hover:bg-brand-strong" wire:navigate>
                    Ver ranking completo
                    <flux:icon.arrow-right class="size-4" />
                </a>
                <a href="{{ route('public.methodology') }}#gestoes" class="inline-flex items-center border border-ink px-5 py-3 font-bold hover:bg-surface" wire:navigate>
                    Entenda o cálculo
                </a>
            </div>
        </header>

        @island(name: 'administrations', defer: true, always: true)
            @placeholder
                <div class="border-t-4 border-brand" aria-label="Carregando ranking das gestões">
                    <div class="grid gap-4 py-5">
                        @foreach (range(1, 5) as $item)
                            <flux:skeleton class="h-20 w-full" wire:key="administration-skeleton-{{ $item }}" />
                        @endforeach
                    </div>
                </div>
            @endplaceholder

            <div class="border-t-4 border-brand">
                <div class="flex flex-col gap-2 border-b border-ink bg-surface px-4 py-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="public-section-title text-3xl">Cinco maiores evoluções</h2>
                        <p class="mt-1 text-sm text-muted">{{ $this->mandateLabel($electionYear) }} · nota ponderada pela cobertura e pelos indicadores disponíveis.</p>
                    </div>
                    <span class="text-sm text-muted">
                        {{ number_format($this->administrations['selected']['meta']['ranked_administrations'] ?? 0, 0, ',', '.') }} gestões classificadas
                    </span>
                </div>
                <ol class="divide-y divide-rule border-b border-ink">
                    @forelse ($this->administrations['selected']['rows'] as $row)
                        @php($summary = $row['evolution_summary'] ?? ['improved' => 0, 'declined' => 0, 'unchanged' => 0, 'not_comparable' => 0])
                        <li wire:key="home-administration-{{ $row['administration']['id'] }}" @class([
                            'grid gap-4 px-4 py-5 sm:grid-cols-[4rem_minmax(0,1fr)_auto] sm:items-center',
                            'sm:py-7' => $loop->first,
                            'bg-surface' => ($selectedExplanation['administration']['id'] ?? null) === $row['administration']['id'],
                        ])>
                            <div @class([
                                'public-numeral flex size-12 items-center justify-center text-3xl sm:size-14',
                                'bg-brand text-ink' => $loop->first,
                            ])>
                                {{ $row['rank'] ?? '—' }}
                            </div>
                            <div class="min-w-0">
                                <button
                                    type="button"
                                    wire:click="showAdministrationBreakdown({{ $row['administration']['id'] }})"
                                    aria-controls="home-administration-breakdown-{{ $row['administration']['id'] }}"
                                    aria-expanded="{{ ($selectedExplanation['administration']['id'] ?? null) === $row['administration']['id'] ? 'true' : 'false' }}"
                                    @class([
                                        'group text-left data-loading:pointer-events-none data-loading:opacity-60',
                                        'text-xl sm:text-2xl' => $loop->first,
                                        'text-lg' => ! $loop->first,
                                    ])
                                >
                                    <span class="font-bold leading-tight">
                                        {{ $row['mayor']['name'] ?? 'Prefeito não importado' }}
                                        <span class="font-normal text-muted">· {{ $row['mayor']['party_acronym'] ?? '—' }}</span>
                                    </span>
                                    <span class="public-link mt-1 flex w-fit items-center gap-1 text-sm font-semibold">
                                        Ver o que mudou
                                        <flux:icon.chevron-down class="size-4 group-data-loading:animate-pulse" />
                                    </span>
                                </button>
                                <a href="{{ route('public.municipality', $row['municipality']['ibge_code']) }}" class="public-link mt-1 inline-block font-semibold" wire:navigate>
                                    {{ $row['municipality']['name'] }}/{{ $row['municipality']['federative_unit'] }}
                                </a>
                                <ul aria-label="Resumo dos indicadores" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
                                    <li><strong class="public-tabular text-ink">{{ $summary['improved'] }}</strong> melhoraram</li>
                                    <li><strong class="public-tabular text-ink">{{ $summary['declined'] }}</strong> pioraram</li>
                                    <li><strong class="public-tabular text-ink">{{ $summary['unchanged'] }}</strong> sem mudança</li>
                                    <li><strong class="public-tabular text-ink">{{ $summary['not_comparable'] }}</strong> não comparáveis</li>
                                </ul>
                            </div>
                            <div class="sm:text-right">
                                <div class="text-xs font-semibold text-muted">Nota de evolução</div>
                                <div @class([
                                    'public-numeral leading-none',
                                    'text-4xl' => $loop->first,
                                    'text-3xl' => ! $loop->first,
                                ])>
                                    {{ $row['evolution_score'] === null ? '—' : ($row['evolution_score'] > 0 ? '+' : '').number_format($row['evolution_score'], 2, ',', '.') }}
                                </div>
                                <div class="mt-1 text-xs text-muted">
                                    {{ number_format($row['coverage_percent'], 1, ',', '.') }}% de cobertura
                                </div>
                            </div>
                        </li>
                        @if (($selectedExplanation['administration']['id'] ?? null) === $row['administration']['id'])
                            <li wire:key="home-administration-breakdown-{{ $row['administration']['id'] }}">
                                <x-public.administration-breakdown
                                    id="home-administration-breakdown-{{ $row['administration']['id'] }}"
                                    :explanation="$selectedExplanation"
                                />
                            </li>
                        @endif
                    @empty
                        <li class="px-4 py-10 text-muted">
                            O ranking será exibido quando houver gestões e indicadores comparáveis suficientes.
                        </li>
                    @endforelse
                </ol>

                <aside class="grid gap-5 bg-surface p-5 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center">
                    <div class="flex size-11 items-center justify-center bg-brand">
                        <flux:icon.clock class="size-5" />
                    </div>
                    <div>
                        <h3 class="font-bold">Mandato 2025–2028 em acompanhamento</h3>
                        <p class="mt-1 text-sm leading-5 text-muted">
                            {{ number_format($this->administrations['current']['meta']['global_updated_weight_percent'] ?? 0, 1, ',', '.') }}%
                            do perfil original avançou de ano efetivo. Posições serão publicadas quando ao menos um indicador completo avançar.
                        </p>
                    </div>
                    <a href="{{ route('public.mayors', ['electionYear' => 2024]) }}" class="public-link w-fit font-semibold" wire:navigate>
                        Ver acompanhamento
                    </a>
                </aside>
            </div>
        @endisland

        <form wire:submit="searchMunicipality" class="grid gap-4 border-t border-rule pt-7 lg:grid-cols-[minmax(0,0.65fr)_minmax(0,1fr)] lg:items-end">
            <div>
                <h2 class="public-section-title text-3xl">Encontre sua cidade</h2>
                <p class="mt-2 max-w-[50ch] text-sm leading-5 text-muted">
                    Consulte a gestão municipal, os valores publicados e a origem de cada observação.
                </p>
            </div>
            <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                <flux:input
                    wire:model="municipalitySearch"
                    aria-label="Buscar município"
                    placeholder="Busque uma cidade pelo nome ou código IBGE"
                    icon="magnifying-glass"
                />
                <flux:button type="submit" variant="primary">Buscar município</flux:button>
            </div>
        </form>
    </section>

    <section aria-labelledby="consolidated-ranking-title" class="grid gap-8">
        <div class="flex flex-col gap-5 border-b border-ink pb-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 id="consolidated-ranking-title" class="public-section-title text-4xl">Ranking consolidado dos municípios</h2>
                <p class="mt-3 max-w-[68ch] text-base text-muted">
                    Consulte o desempenho atual calculado com o último dado oficial disponível em cada fonte.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm font-semibold">
                <label for="home-year">Exercício</label>
                <select id="home-year" wire:model.live="year" class="border border-ink bg-white px-3 py-2 font-semibold">
                    @foreach (range(2025, 2017) as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @island(name: 'consolidated-table', lazy: true)
            @placeholder
                <div class="border-t-4 border-brand p-5" aria-label="Carregando ranking consolidado">
                    <flux:skeleton class="h-8 w-48" />
                    <div class="mt-6 grid gap-4">
                        @foreach (range(1, 5) as $item)
                            <flux:skeleton class="h-12 w-full" wire:key="leader-skeleton-{{ $item }}" />
                        @endforeach
                    </div>
                </div>
            @endplaceholder

            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="border-t-4 border-brand">
                    <div class="flex items-end justify-between gap-4 border-b border-rule bg-surface px-4 py-4">
                        <h3 class="public-section-title text-3xl">Cinco primeiras cidades</h3>
                        <span class="text-sm text-muted">Brasil · {{ $year }}</span>
                    </div>
                    <ol class="divide-y divide-rule border-b border-ink">
                        @forelse ($this->consolidated['rows'] as $row)
                            <li wire:key="home-rank-{{ $row['municipality']['ibge_code'] }}" class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-4">
                                <span class="public-numeral text-2xl">{{ $row['rank'] }}</span>
                                <a href="{{ route('public.municipality', $row['municipality']['ibge_code']) }}" class="public-link font-semibold" wire:navigate>
                                    {{ $row['municipality']['name'] }}/{{ $row['municipality']['federative_unit'] }}
                                </a>
                                <span class="public-numeral text-xl">{{ number_format($row['score'], 2, ',', '.') }}</span>
                            </li>
                        @empty
                            <li class="px-4 py-10 text-muted">O ranking será exibido após a primeira carga de dados.</li>
                        @endforelse
                    </ol>
                </div>
                <aside class="grid content-start gap-5 bg-surface p-5">
                    <h3 class="public-section-title text-3xl">Sobre a nota</h3>
                    <p class="text-sm leading-6 text-muted">
                        O cálculo respeita a direção de cada indicador, exige cobertura mínima de
                        {{ $this->consolidated['meta']['minimum_coverage_percent'] ?? 60 }}% e não transforma ausência de dados em zero.
                    </p>
                    <p class="text-xs leading-5 text-muted">
                        Anos efetivos:
                        {{ collect($this->consolidated['meta']['effective_years'] ?? [])->unique()->sort()->implode(', ') ?: 'aguardando dados' }}.
                    </p>
                    <div class="grid gap-3">
                        <a href="{{ route('public.ranking', ['year' => $year]) }}" class="inline-flex items-center justify-center gap-2 bg-brand px-4 py-3 font-bold text-ink hover:bg-brand-strong" wire:navigate>
                            Explorar ranking
                            <flux:icon.arrow-right class="size-4" />
                        </a>
                        <a href="{{ route('public.methodology') }}" class="public-link w-fit font-semibold" wire:navigate>
                            Entenda o cálculo
                        </a>
                    </div>
                </aside>
            </div>
        @endisland
    </section>

    <section aria-labelledby="indicator-rankings-title" class="grid gap-8">
        <div class="flex flex-col gap-4 border-b border-ink pb-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 id="indicator-rankings-title" class="public-section-title text-4xl sm:text-5xl">Rankings por indicador</h2>
                <p class="mt-3 max-w-[68ch] text-base text-muted">
                    Veja líderes, cobertura e o ano efetivamente publicado em cada base oficial.
                </p>
            </div>
            <a href="{{ route('public.open-data') }}" class="public-link w-fit font-semibold" wire:navigate>Ver catálogo completo</a>
        </div>

        @island(name: 'indicator-rankings', lazy: true)
            @placeholder
                <div class="grid gap-8 md:grid-cols-2">
                    @foreach (range(1, 4) as $item)
                        <div class="border-t border-ink pt-4" wire:key="indicator-skeleton-{{ $item }}">
                            <flux:skeleton class="h-8 w-40" />
                            <flux:skeleton class="mt-5 h-48 w-full" />
                        </div>
                    @endforeach
                </div>
            @endplaceholder

            <div class="grid gap-x-10 gap-y-12 md:grid-cols-2">
                @foreach ($this->indicatorGroups as $theme => $indicators)
                    <section wire:key="theme-{{ $theme }}" class="border-t border-ink pt-4">
                        <h3 class="public-section-title text-3xl">{{ $this->themeLabel($theme) }}</h3>
                        <div class="mt-3 divide-y divide-rule">
                            @foreach ($indicators as $indicator)
                                <article wire:key="indicator-{{ $indicator['slug'] }}" class="grid gap-4 py-5">
                                    <div class="flex items-start gap-4">
                                        <div class="flex size-10 shrink-0 items-center justify-center bg-brand text-ink">
                                            <flux:icon :name="$this->iconFor($indicator['slug'])" class="size-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                <h4 class="font-bold leading-5">{{ $indicator['name'] }}</h4>
                                                @if ($indicator['status'] === 'available')
                                                    <span class="text-xs font-semibold text-muted">ref. {{ $indicator['effective_year'] }}</span>
                                                @else
                                                    <span class="bg-surface px-2 py-1 text-xs font-semibold">Aguardando dados</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-sm text-muted">
                                                Cobertura {{ number_format($indicator['coverage_percent'], 1, ',', '.') }}%
                                            </p>
                                        </div>
                                    </div>

                                    @if ($indicator['leaders'] !== [])
                                        <ol class="grid gap-2 pl-14 text-sm">
                                            @foreach ($indicator['leaders'] as $index => $leader)
                                                <li wire:key="{{ $indicator['slug'] }}-{{ $leader['ibge_code'] }}" class="grid grid-cols-[1.5rem_1fr_auto] gap-2">
                                                    <span class="public-numeral">{{ $index + 1 }}</span>
                                                    <span class="truncate">{{ $leader['name'] }}/{{ $leader['federative_unit'] }}</span>
                                                    <strong class="public-tabular">{{ $this->formatValue($leader['value'], $indicator['unit']) }}</strong>
                                                </li>
                                            @endforeach
                                        </ol>
                                        <a href="{{ $indicator['ranking_url'] }}" class="public-link ml-14 w-fit text-sm font-semibold" wire:navigate>Ver ranking completo</a>
                                    @else
                                        <p class="pl-14 text-sm text-muted">
                                            A publicação será ativada quando houver uma série oficial utilizável.
                                        </p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endisland
    </section>

    <section class="grid gap-6 border-y border-ink py-8 lg:grid-cols-3">
        <div>
            <div class="public-numeral text-4xl">{{ number_format($this->freshness['municipalities'], 0, ',', '.') }}</div>
            <p class="mt-1 text-sm text-muted">municípios acompanhados</p>
        </div>
        <div>
            <div class="public-numeral text-4xl">{{ number_format($this->freshness['official_sources'], 0, ',', '.') }}</div>
            <p class="mt-1 text-sm text-muted">fontes com releases vigentes</p>
        </div>
        <div>
            <div class="public-numeral text-4xl">{{ $this->freshness['latest_collection_date'] ? \Carbon\Carbon::parse($this->freshness['latest_collection_date'])->format('d/m/Y') : '—' }}</div>
            <p class="mt-1 text-sm text-muted">última coleta registrada{{ $this->freshness['latest_source'] ? ' · '.$this->freshness['latest_source'] : '' }}</p>
        </div>
    </section>
</div>
