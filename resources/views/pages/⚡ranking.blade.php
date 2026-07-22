<?php

use App\Actions\MunicipalRanking\CalculateMunicipalRanking;
use App\DTO\MunicipalRanking\RankingQueryData;
use App\Models\Indicator;
use App\Support\MunicipalRanking\NationalIndicatorCoverage;
use App\Support\MunicipalRanking\RankingMethodologyCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Ranking municipal')] class extends Component {
    #[Url]
    public int $year = 2025;

    #[Url]
    public string $theme = '';

    #[Url]
    public string $uf = '';

    #[Url]
    public string $populationMin = '';

    #[Url]
    public string $populationMax = '';

    #[Url]
    public string $search = '';

    #[Url]
    public array $weights = [];

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public ?string $rankingError = null;

    public ?array $selectedExplanation = null;

    public function applyFilters(): void
    {
        $this->page = 1;
        $this->selectedExplanation = null;
        unset($this->ranking);
    }

    public function nextPage(): void
    {
        if ($this->page < $this->ranking['meta']['pagination']['last_page']) {
            $this->page++;
            $this->selectedExplanation = null;
            unset($this->ranking);
        }
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->selectedExplanation = null;
            unset($this->ranking);
        }
    }

    public function showExplanation(string $ibgeCode): void
    {
        $this->selectedExplanation = app(CalculateMunicipalRanking::class)
            ->explanation($this->queryData(), $ibgeCode);
    }

    public function closeExplanation(): void
    {
        $this->selectedExplanation = null;
    }

    #[Computed]
    public function ranking(): array
    {
        try {
            $query = $this->queryData();
            $result = app(CalculateMunicipalRanking::class)->all($query);
            $this->rankingError = null;
        } catch (\InvalidArgumentException $exception) {
            $this->rankingError = $exception->getMessage();

            return [
                'rows' => [],
                'meta' => [
                    'methodology_version' => (string) config('municipal_ranking.methodology_version'),
                    'minimum_coverage_percent' => (float) config('municipal_ranking.minimum_coverage') * 100,
                    'pagination' => ['page' => 1, 'per_page' => $this->perPage, 'total' => 0, 'last_page' => 1],
                ],
            ];
        }
        $rows = collect($result['rows']);

        if ($this->search !== '') {
            $needle = Str::lower(Str::ascii($this->search));
            $rows = $rows->filter(function (array $row) use ($needle): bool {
                $haystack = Str::lower(Str::ascii($row['municipality']['name'].' '.$row['municipality']['ibge_code']));

                return Str::contains($haystack, $needle);
            });
        }

        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $this->perPage));
        $this->page = min($this->page, $lastPage);

        return [
            'rows' => $rows->slice(($this->page - 1) * $this->perPage, $this->perPage)->values()->all(),
            'meta' => $result['meta'] + [
                'pagination' => [
                    'page' => $this->page,
                    'per_page' => $this->perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
            ],
        ];
    }

    #[Computed]
    public function indicators(): Collection
    {
        $eligible = app(RankingMethodologyCatalog::class)->customWeightIndicators();
        $visibleSlugs = array_keys(app(NationalIndicatorCoverage::class)->completeEffectiveYears(
            $this->year,
            $eligible,
        ));

        return Indicator::query()
            ->whereIn('slug', $visibleSlugs)
            ->where('is_active', true)
            ->orderBy('theme')
            ->orderBy('name')
            ->get(['slug', 'name', 'theme']);
    }

    private function queryData(): RankingQueryData
    {
        return new RankingQueryData(
            year: $this->year,
            theme: $this->theme ?: null,
            federativeUnit: $this->uf ? Str::upper($this->uf) : null,
            populationMin: $this->populationMin !== '' ? (int) $this->populationMin : null,
            populationMax: $this->populationMax !== '' ? (int) $this->populationMax : null,
            weights: $this->normalizedWeights(),
            perPage: 100,
        );
    }

    /** @return array<string, float> */
    private function normalizedWeights(): array
    {
        $visibleSlugs = $this->indicators->pluck('slug')->all();

        return collect($this->weights)
            ->only($visibleSlugs)
            ->filter(fn ($weight): bool => $weight !== '' && $weight !== null)
            ->map(fn ($weight): float => (float) $weight)
            ->all();
    }
}; ?>

<div class="grid gap-10">
    <header class="border-b border-ink pb-8">
        <span class="inline-block bg-brand px-3 py-1 text-sm font-bold">Metodologia {{ $this->ranking['meta']['methodology_version'] }}</span>
        <h1 class="public-display mt-5 text-5xl sm:text-6xl">Ranking municipal transparente</h1>
        <p class="mt-4 max-w-[68ch] text-lg leading-7 text-muted">
            Consulte os indicadores municipais pelo último dado oficial disponível até o exercício escolhido. Dados ausentes nunca viram zero.
        </p>
    </header>

    <form wire:submit="applyFilters" class="border-y border-rule bg-surface p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <flux:field>
                <flux:label>Exercício</flux:label>
                <select wire:model="year" class="border border-rule bg-white px-3 py-2">
                    @foreach (range(2025, 2017) as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </flux:field>
            <flux:field>
                <flux:label>Tema</flux:label>
                <select wire:model="theme" class="border border-rule bg-white px-3 py-2">
                    <option value="">Todos</option>
                    <option value="economia">Economia</option>
                    <option value="educacao">Educação</option>
                    <option value="saneamento">Saneamento</option>
                    <option value="seguranca">Segurança</option>
                </select>
            </flux:field>
            <flux:input wire:model="uf" label="UF" maxlength="2" placeholder="SP" />
            <flux:input wire:model="populationMin" type="number" min="0" label="População mínima" />
            <flux:input wire:model="populationMax" type="number" min="0" label="População máxima" />
            <flux:input wire:model="search" label="Buscar município" placeholder="Nome ou IBGE" />
        </div>

        <details class="mt-5">
            <summary class="cursor-pointer text-sm font-medium">Configurar pesos por indicador</summary>
            <p class="mt-2 text-sm text-muted">Deixe todos vazios para usar 25% por tema. Os valores informados serão normalizados para 100%.</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->indicators as $indicator)
                    <flux:input
                        wire:model="weights.{{ $indicator->slug }}"
                        type="number"
                        min="0"
                        step="0.01"
                        :label="$indicator->name"
                        :description="$indicator->theme"
                    />
                @endforeach
            </div>
        </details>

        <div class="mt-5 flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary" icon="adjustments-horizontal">Aplicar filtros</flux:button>
        </div>
    </form>

    <div wire:loading class="w-full bg-brand p-4 text-sm font-semibold text-ink">
        Atualizando o recorte do ranking…
    </div>

    @if ($rankingError)
        <div class="border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            {{ $rankingError }}
        </div>
    @endif

    <section wire:loading.remove class="overflow-hidden border-y border-ink bg-white">
        <div class="flex flex-col gap-2 border-b border-rule bg-surface px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold">{{ number_format($this->ranking['meta']['pagination']['total'], 0, ',', '.') }} municípios encontrados</h2>
                <p class="text-sm text-muted">Cobertura mínima: {{ $this->ranking['meta']['minimum_coverage_percent'] }}%</p>
            </div>
            <span class="text-xs text-muted">Dados recalculados dentro deste grupo</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="text-left text-xs font-bold uppercase tracking-wider text-muted">
                    <tr>
                        <th class="px-5 py-3">Posição</th>
                        <th class="px-5 py-3">Município</th>
                        <th class="px-5 py-3">População</th>
                        <th class="px-5 py-3">Nota</th>
                        <th class="px-5 py-3">Cobertura</th>
                        <th class="px-5 py-3">Explicação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule">
                    @forelse ($this->ranking['rows'] as $row)
                        <tr wire:key="rank-{{ $row['municipality']['ibge_code'] }}" class="align-top">
                            <td class="public-numeral px-5 py-4 text-xl">{{ $row['rank'] ?? '—' }}</td>
                            <td class="px-5 py-4">
                                <a class="font-medium hover:underline" href="{{ route('public.municipality', $row['municipality']['ibge_code']) }}" wire:navigate>
                                    {{ $row['municipality']['name'] }}/{{ $row['municipality']['federative_unit'] }}
                                </a>
                                <div class="text-xs text-muted">{{ $row['municipality']['ibge_code'] }}</div>
                            </td>
                            <td class="px-5 py-4">
                                {{ $row['population'] === null ? '—' : number_format($row['population'], 0, ',', '.') }}
                                <div class="text-xs text-muted">ref. {{ $row['population_reference_year'] ?? '—' }}</div>
                            </td>
                            <td class="public-numeral px-5 py-4 text-lg">{{ $row['score'] === null ? '—' : number_format($row['score'], 2, ',', '.') }}</td>
                            <td class="px-5 py-4">{{ number_format($row['coverage_percent'], 1, ',', '.') }}%</td>
                            <td class="px-5 py-4">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="showExplanation('{{ $row['municipality']['ibge_code'] }}')"
                                >
                                    {{ $row['status'] === 'ranked' ? 'Abrir composição' : 'Ver dados disponíveis' }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-12 text-center text-muted">Nenhum município encontrado para estes filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between border-t border-rule px-5 py-4">
            <flux:button wire:click="previousPage" :disabled="$page <= 1">Anterior</flux:button>
            <span class="text-sm">Página {{ $page }} de {{ $this->ranking['meta']['pagination']['last_page'] }}</span>
            <flux:button wire:click="nextPage" :disabled="$page >= $this->ranking['meta']['pagination']['last_page']">Próxima</flux:button>
        </div>
    </section>

    @if ($selectedExplanation)
        <section class="border-t-4 border-brand bg-surface p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="public-section-title text-3xl">
                        Composição de {{ $selectedExplanation['municipality']['name'] }}/{{ $selectedExplanation['municipality']['federative_unit'] }}
                    </h2>
                    <flux:text>
                        Nota {{ $selectedExplanation['score'] === null ? 'indisponível' : number_format($selectedExplanation['score'], 2, ',', '.') }}
                        · cobertura {{ number_format($selectedExplanation['coverage_percent'], 1, ',', '.') }}%
                    </flux:text>
                </div>
                <flux:button wire:click="closeExplanation" size="sm" variant="ghost">Fechar</flux:button>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ($selectedExplanation['components'] as $component)
                    <div wire:key="explanation-{{ $selectedExplanation['municipality']['ibge_code'] }}-{{ $component['indicator'] }}" class="border border-rule bg-white p-4">
                        <div class="flex justify-between gap-4">
                            <span class="font-medium">{{ $component['name'] }}</span>
                            <span>{{ $component['available'] ? number_format($component['raw_value'], 2, ',', '.') : 'ausente' }}</span>
                        </div>
                        <div class="mt-2 text-sm text-muted">
                            Ano {{ $component['effective_year'] }}
                            · percentil {{ ($component['percentile'] ?? null) === null ? '—' : number_format($component['percentile'], 2, ',', '.') }}
                            · peso efetivo {{ number_format($component['effective_weight'], 2, ',', '.') }}%
                        </div>
                        @if ($component['source']['url'] ?? null)
                            <a class="public-link mt-2 inline-block text-sm font-semibold" href="{{ $component['source']['url'] }}" target="_blank" rel="noopener">
                                Abrir fonte oficial
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
