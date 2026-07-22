<?php

use App\Models\DataSource;
use App\Models\Indicator;
use App\Support\MunicipalRanking\NationalIndicatorCoverage;
use App\Support\MunicipalRanking\RankingMethodologyCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Dados e fontes')] class extends Component {
    #[Computed]
    public function indicators(): array
    {
        $eligible = app(RankingMethodologyCatalog::class)->customWeightIndicators();
        $visibleSlugs = array_keys(app(NationalIndicatorCoverage::class)->completeEffectiveYears(
            (int) config('municipal_ranking.maximum_year'),
            $eligible,
        ));

        return Indicator::query()
            ->with(['versions' => fn ($builder) => $builder->latest('version')->limit(1)])
            ->whereIn('slug', $visibleSlugs)
            ->where('is_active', true)
            ->orderBy('theme')
            ->orderBy('name')
            ->get()
            ->map(fn (Indicator $indicator): array => [
                'slug' => $indicator->slug,
                'name' => $indicator->name,
                'description' => $indicator->description,
                'theme' => $indicator->theme,
                'unit' => $indicator->unit,
                'direction' => $indicator->rankingDirection()->value,
                'periodicity' => $indicator->periodicityValue()->value,
                'methodology_url' => $indicator->versions->first()?->methodology_url,
            ])
            ->all();
    }

    #[Computed]
    public function sources(): array
    {
        return DataSource::query()
            ->withCount(['releases' => fn ($builder) => $builder->whereNull('superseded_by_id')])
            ->where('is_active', true)
            ->orderBy('publisher')
            ->orderBy('name')
            ->get()
            ->map(fn (DataSource $source): array => [
                'name' => $source->name,
                'publisher' => $source->publisher,
                'acquisition_method' => $source->acquisition_method,
                'homepage_url' => $source->homepage_url,
                'releases_count' => (int) $source->getAttribute('releases_count'),
            ])
            ->all();
    }
}; ?>

<div class="grid gap-14">
    <header class="border-b border-ink pb-8">
        <h1 class="public-display text-5xl sm:text-6xl">Dados e fontes</h1>
        <p class="mt-4 max-w-[68ch] text-lg leading-7 text-muted">
            Catálogo e fontes oficiais usados para acompanhar as gestões de prefeitos e os indicadores municipais.
        </p>
    </header>

    <section class="grid gap-6">
        <div class="flex flex-col gap-3 border-b border-ink pb-4 sm:flex-row sm:items-end sm:justify-between">
            <h2 class="public-section-title text-4xl">Indicadores do ranking</h2>
            <span class="text-sm text-muted">{{ count($this->indicators) }} indicadores elegíveis</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-surface text-left text-xs font-bold uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Indicador</th>
                        <th class="px-4 py-3">Tema</th>
                        <th class="px-4 py-3">Unidade</th>
                        <th class="px-4 py-3">Direção</th>
                        <th class="px-4 py-3">Periodicidade</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule">
                    @foreach ($this->indicators as $indicator)
                        <tr wire:key="catalog-{{ $indicator['slug'] }}">
                            <td class="px-4 py-4">
                                <strong>{{ $indicator['name'] }}</strong>
                                <div class="mt-1 max-w-[65ch] text-muted">{{ $indicator['description'] }}</div>
                                @if ($indicator['methodology_url'])
                                    <a href="{{ $indicator['methodology_url'] }}" target="_blank" rel="noopener" class="public-link mt-2 inline-block font-semibold">Metodologia oficial</a>
                                @endif
                            </td>
                            <td class="px-4 py-4">{{ ucfirst($indicator['theme']) }}</td>
                            <td class="px-4 py-4">{{ $indicator['unit'] }}</td>
                            <td class="px-4 py-4">{{ $indicator['direction'] === 'lower_is_better' ? 'Menor é melhor' : 'Maior é melhor' }}</td>
                            <td class="px-4 py-4">{{ $indicator['periodicity'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-6">
        <div class="border-b border-ink pb-4">
            <h2 class="public-section-title text-4xl">Fontes registradas</h2>
        </div>
        <div class="grid gap-x-8 gap-y-0 md:grid-cols-2">
            @foreach ($this->sources as $source)
                <article wire:key="source-{{ $source['publisher'] }}-{{ $source['name'] }}" class="border-b border-rule py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-bold">{{ $source['name'] }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ $source['publisher'] }} · {{ $source['acquisition_method'] }}</p>
                        </div>
                        <span class="public-tabular text-sm text-muted">{{ $source['releases_count'] }} releases</span>
                    </div>
                    <a href="{{ $source['homepage_url'] }}" target="_blank" rel="noopener" class="public-link mt-3 inline-block text-sm font-semibold">Abrir fonte oficial</a>
                </article>
            @endforeach
        </div>
    </section>
</div>
