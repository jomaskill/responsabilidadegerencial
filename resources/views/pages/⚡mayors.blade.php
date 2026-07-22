<?php

use App\Actions\MunicipalRanking\CalculateAdministrationEvolutionRanking;
use App\DTO\MunicipalRanking\AdministrationEvolutionQueryData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Gestões de prefeitos')] class extends Component {
    #[Url(history: true)]
    public int $electionYear = 2020;

    #[Url]
    public string $uf = '';

    #[Url]
    public string $populationMin = '';

    #[Url]
    public string $populationMax = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public ?array $selectedExplanation = null;

    public function applyFilters(): void
    {
        $this->page = 1;
        $this->selectedExplanation = null;
        unset($this->ranking);
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
        $this->selectedExplanation = null;
        unset($this->ranking);
    }

    public function nextPage(): void
    {
        $this->page = min($this->ranking['meta']['pagination']['last_page'], $this->page + 1);
        $this->selectedExplanation = null;
        unset($this->ranking);
    }

    public function showExplanation(int $administrationId): void
    {
        $this->selectedExplanation = app(CalculateAdministrationEvolutionRanking::class)
            ->explanation($this->queryData(), $administrationId);
    }

    #[Computed]
    public function ranking(): array
    {
        $result = app(CalculateAdministrationEvolutionRanking::class)->execute($this->queryData());

        return ['rows' => $result->rows, 'meta' => $result->meta];
    }

    private function queryData(): AdministrationEvolutionQueryData
    {
        return new AdministrationEvolutionQueryData(
            electionYear: $this->electionYear,
            federativeUnit: $this->uf !== '' ? mb_strtoupper($this->uf) : null,
            populationMin: $this->populationMin !== '' ? (int) $this->populationMin : null,
            populationMax: $this->populationMax !== '' ? (int) $this->populationMax : null,
            page: $this->page,
            perPage: $this->perPage,
        );
    }
}; ?>

<div class="grid gap-10">
    <header class="grid gap-5 border-b border-ink pb-8 lg:grid-cols-[1fr_auto] lg:items-end">
        <div>
            <h1 class="public-display text-5xl sm:text-6xl">Gestões de prefeitos</h1>
            <p class="mt-4 max-w-[68ch] text-lg leading-7 text-muted">
                Acompanhe a mudança de posição relativa dos municípios durante cada mandato, sem atribuir causalidade ao prefeito.
            </p>
        </div>
        <a href="{{ route('public.methodology') }}#gestoes" class="public-link font-semibold" wire:navigate>Como este ranking é calculado</a>
    </header>

    <form wire:submit="applyFilters" class="grid gap-5 border-y border-rule bg-surface p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:field>
                <flux:label>Mandato</flux:label>
                <select wire:model="electionYear" class="border border-rule bg-white px-3 py-2">
                    <option value="2020">2021–2024</option>
                    <option value="2016">2017–2020</option>
                    <option value="2024">2025–2028</option>
                </select>
            </flux:field>
            <flux:input wire:model="uf" label="UF" maxlength="2" placeholder="SP" />
            <flux:input wire:model="populationMin" type="number" min="0" label="População mínima" />
            <flux:input wire:model="populationMax" type="number" min="0" label="População máxima" />
        </div>
        <div class="flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary" icon="adjustments-horizontal">Aplicar filtros</flux:button>
        </div>
    </form>

    <div wire:loading class="bg-brand px-4 py-3 font-semibold">Atualizando o ranking do mandato…</div>

    @if (! $this->ranking['meta']['ranking_available'])
        <section wire:loading.remove class="grid gap-5 border-t-4 border-brand bg-surface p-6 sm:p-8">
            <flux:icon.clock class="size-8" />
            <div>
                <h2 class="public-section-title text-3xl">Acompanhamento iniciado — ainda sem posição</h2>
                <p class="mt-3 max-w-[68ch] text-base leading-6 text-muted">
                    {{ number_format($this->ranking['meta']['global_updated_weight_percent'], 1, ',', '.') }}%
                    do perfil original avançou de ano efetivo. Posições serão publicadas quando ao menos um indicador completo avançar.
                </p>
            </div>
        </section>
    @endif

    <section wire:loading.remove class="overflow-hidden border-y border-ink">
        <div class="flex flex-col gap-2 bg-surface px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <strong>{{ number_format($this->ranking['meta']['pagination']['total'], 0, ',', '.') }} gestões</strong>
                <div class="text-sm text-muted">
                    Base {{ $this->ranking['meta']['baseline_year'] }} · final {{ $this->ranking['meta']['end_year'] }}
                </div>
            </div>
            <span class="text-sm text-muted">Cobertura municipal mínima de 60%</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-y border-rule text-left text-xs font-bold uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Posição</th>
                        <th class="px-4 py-3">Prefeito</th>
                        <th class="px-4 py-3">Município</th>
                        <th class="px-4 py-3 text-right">Evolução</th>
                        <th class="px-4 py-3 text-right">Cobertura</th>
                        <th class="px-4 py-3">Detalhes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule">
                    @forelse ($this->ranking['rows'] as $row)
                        <tr
                            wire:key="mayor-ranking-{{ $row['administration']['id'] }}"
                            @class([
                                'align-top',
                                'bg-surface' => ($selectedExplanation['administration']['id'] ?? null) === $row['administration']['id'],
                            ])
                        >
                            <td class="public-numeral px-4 py-4 text-2xl">{{ $row['rank'] ?? '—' }}</td>
                            <td class="px-4 py-4">
                                @if ($row['status'] !== 'awaiting_new_data')
                                    <button
                                        type="button"
                                        wire:click="showExplanation({{ $row['administration']['id'] }})"
                                        aria-controls="mayor-administration-breakdown-{{ $row['administration']['id'] }}"
                                        aria-expanded="{{ ($selectedExplanation['administration']['id'] ?? null) === $row['administration']['id'] ? 'true' : 'false' }}"
                                        class="group text-left data-loading:pointer-events-none data-loading:opacity-60"
                                    >
                                        <strong class="public-link">{{ $row['mayor']['name'] ?? 'Não importado' }}</strong>
                                        <span class="mt-1 flex w-fit items-center gap-1 text-xs font-semibold text-muted">
                                            {{ $row['mayor']['party_acronym'] ?? '—' }} · ver o que mudou
                                            <flux:icon.chevron-down class="size-3.5" />
                                        </span>
                                    </button>
                                @else
                                    <strong>{{ $row['mayor']['name'] ?? 'Não importado' }}</strong>
                                    <div class="text-muted">{{ $row['mayor']['party_acronym'] ?? '—' }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <a href="{{ route('public.municipality', $row['municipality']['ibge_code']) }}" class="public-link font-semibold" wire:navigate>
                                    {{ $row['municipality']['name'] }}/{{ $row['municipality']['federative_unit'] }}
                                </a>
                            </td>
                            <td class="public-numeral px-4 py-4 text-right text-lg">
                                {{ $row['evolution_score'] === null ? '—' : ($row['evolution_score'] > 0 ? '+' : '').number_format($row['evolution_score'], 2, ',', '.') }}
                            </td>
                            <td class="public-tabular px-4 py-4 text-right">{{ number_format($row['coverage_percent'], 1, ',', '.') }}%</td>
                            <td class="px-4 py-4">
                                @if ($row['status'] !== 'awaiting_new_data')
                                    <flux:button size="sm" variant="ghost" wire:click="showExplanation({{ $row['administration']['id'] }})">
                                        Ver avanços
                                    </flux:button>
                                @else
                                    <span class="text-muted">Aguardando fontes</span>
                                @endif
                            </td>
                        </tr>
                        @if (($selectedExplanation['administration']['id'] ?? null) === $row['administration']['id'])
                            <tr wire:key="mayor-administration-breakdown-{{ $row['administration']['id'] }}">
                                <td colspan="6" class="p-0">
                                    <x-public.administration-breakdown
                                        id="mayor-administration-breakdown-{{ $row['administration']['id'] }}"
                                        :explanation="$selectedExplanation"
                                    />
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-muted">
                                Nenhum prefeito importado para este mandato e filtro.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex items-center justify-between gap-4 border-t border-rule px-4 py-4">
            <flux:button wire:click="previousPage" :disabled="$page <= 1">Anterior</flux:button>
            <span class="text-sm">Página {{ $page }} de {{ $this->ranking['meta']['pagination']['last_page'] }}</span>
            <flux:button wire:click="nextPage" :disabled="$page >= $this->ranking['meta']['pagination']['last_page']">Próxima</flux:button>
        </div>
    </section>

</div>
