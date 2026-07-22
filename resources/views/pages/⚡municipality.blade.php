<?php

use App\Actions\MunicipalRanking\CalculateMunicipalRanking;
use App\DTO\MunicipalRanking\RankingQueryData;
use App\Models\Municipality;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Ficha municipal')] class extends Component {
    public string $ibgeCode;

    #[Url(as: 'ano', history: true)]
    public int $year = 2025;

    public function mount(string $ibgeCode): void
    {
        $this->ibgeCode = $ibgeCode;

        if (! in_array($this->year, range(2017, 2025), true)) {
            $this->year = 2025;
        }
    }

    public function updatedYear(): void
    {
        if (! in_array($this->year, range(2017, 2025), true)) {
            $this->year = 2025;
        }

        unset($this->evolution);
    }

    #[Computed]
    public function municipality(): Municipality
    {
        return Municipality::query()
            ->with(['federativeUnit', 'administrations.officeHolders.sourceRelease'])
            ->where('ibge_code', $this->ibgeCode)
            ->firstOrFail();
    }

    #[Computed]
    public function evolution(): array
    {
        try {
            $row = app(CalculateMunicipalRanking::class)->explanation(
                new RankingQueryData(year: $this->year, perPage: 100),
                $this->ibgeCode,
            );
        } catch (\InvalidArgumentException) {
            $row = null;
        }

        return [
            'year' => $this->year,
            'score' => $row['score'] ?? null,
            'rank' => $row['rank'] ?? null,
            'coverage' => $row['coverage_percent'] ?? null,
            'components' => $row['components'] ?? [],
        ];
    }
}; ?>

<div class="grid gap-10">
    <header class="border-b border-ink pb-8">
        <a href="{{ route('public.ranking') }}" class="public-link text-sm font-semibold" wire:navigate>← Voltar ao ranking</a>
        <h1 class="public-display mt-5 text-5xl sm:text-6xl">{{ $this->municipality->name }}/{{ $this->municipality->federativeUnit->acronym }}</h1>
        <p class="public-tabular mt-2 text-muted">Código IBGE {{ $this->municipality->ibge_code }}</p>
    </header>

    <section class="grid gap-4 md:grid-cols-2">
        @forelse ($this->municipality->administrations->sortByDesc('term_start') as $administration)
            <article class="border-t-4 border-brand bg-surface p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="public-section-title text-2xl">Gestão {{ $administration->term_start->year }}–{{ $administration->term_end->year }}</h2>
                        <p class="text-sm text-muted">Eleição geral de {{ $administration->election_year }}</p>
                    </div>
                    <flux:badge>{{ $administration->status }}</flux:badge>
                </div>
                @foreach ($administration->officeHolders as $holder)
                    <div class="mt-5">
                        <div class="font-medium">{{ $holder->name }}</div>
                        <div class="text-sm text-muted">Prefeito(a) · {{ $holder->party_acronym ?: 'partido não informado' }}</div>
                        @if ($holder->source_url)
                            <a class="public-link mt-2 inline-block text-xs font-semibold" href="{{ $holder->source_url }}" target="_blank" rel="noopener">Registro oficial do TSE</a>
                        @endif
                    </div>
                @endforeach
            </article>
        @empty
            <div class="border border-dashed border-rule p-5 text-muted">
                A carga oficial das gestões ainda não foi executada neste ambiente.
            </div>
        @endforelse
    </section>

    <section class="border-y border-ink py-5">
        <h2 class="public-section-title text-4xl">Evolução 2017–2025</h2>
        <p class="mt-2 text-base text-muted">Consulte um exercício por vez. A posição é relativa ao Brasil; o ano real de cada fonte aparece nos detalhes.</p>

        @island(name: 'municipality-evolution', lazy: true)
            @placeholder
                <div class="mt-5 border-t-4 border-brand py-5" aria-label="Carregando indicadores do município">
                    <flux:skeleton.group animate="shimmer" class="grid gap-4">
                        <flux:skeleton class="h-10 w-48" />
                        <flux:skeleton class="h-24 w-full" />
                    </flux:skeleton.group>
                </div>
            @endplaceholder

            @php($evolution = $this->evolution)

            <div class="mt-5 border-t-4 border-brand">
                <div class="flex flex-col gap-3 border-b border-rule bg-surface px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="public-section-title text-2xl">Desempenho no exercício</h3>
                        <p class="text-sm text-muted">Os dados oficiais podem ter ano de referência anterior ao exercício selecionado.</p>
                    </div>
                    <div class="flex items-center gap-3 text-sm font-semibold">
                        <label for="municipality-year">Exercício</label>
                        <select id="municipality-year" wire:model.live="year" class="border border-ink bg-white px-3 py-2 font-semibold">
                            @foreach (range(2025, 2017) as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-bold uppercase tracking-wider">
                            <tr><th class="px-4 py-3">Exercício</th><th>Nota</th><th>Posição</th><th>Cobertura</th><th class="pr-4">Valores e fontes</th></tr>
                        </thead>
                        <tbody class="border-t border-rule">
                        <tr class="align-top">
                            <td class="public-numeral px-4 py-4 text-lg">{{ $evolution['year'] }}</td>
                            <td class="public-tabular">{{ $evolution['score'] === null ? '—' : number_format($evolution['score'], 2, ',', '.') }}</td>
                            <td class="public-tabular">{{ $evolution['rank'] ?? '—' }}</td>
                            <td class="public-tabular">{{ $evolution['coverage'] === null ? '—' : number_format($evolution['coverage'], 1, ',', '.').'%' }}</td>
                            <td class="py-4 pr-4">
                                <details>
                                    <summary class="public-link cursor-pointer font-semibold">Ver indicadores</summary>
                                    <ul class="mt-2 space-y-2">
                                        @forelse ($evolution['components'] as $component)
                                            <li>
                                                {{ $component['name'] }}:
                                                {{ $component['available'] ? number_format($component['raw_value'], 2, ',', '.') : 'ausente' }}
                                                <span class="text-muted">(fonte ref. {{ $component['effective_year'] }})</span>
                                                @if ($component['source']['url'] ?? null)
                                                    · <a class="underline" href="{{ $component['source']['url'] }}" target="_blank" rel="noopener">fonte</a>
                                                @endif
                                            </li>
                                        @empty
                                            <li class="text-muted">Ainda não há indicadores comparáveis para este exercício.</li>
                                        @endforelse
                                    </ul>
                                </details>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endisland
    </section>
</div>
