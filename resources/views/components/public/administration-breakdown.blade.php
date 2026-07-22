@props([
    'explanation',
    'id' => 'administration-breakdown',
])

@php
    $summary = $explanation['evolution_summary'] ?? [
        'improved' => 0,
        'declined' => 0,
        'unchanged' => 0,
        'not_comparable' => 0,
    ];

    $formatValue = static function (?float $value, string $unit): string {
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
    };

    $themeLabel = static fn (string $theme): string => match ($theme) {
        'economia' => 'Economia',
        'educacao' => 'Educação',
        'saneamento' => 'Saneamento',
        'seguranca' => 'Segurança',
        default => ucfirst($theme),
    };
@endphp

<section id="{{ $id }}" aria-live="polite" {{ $attributes->class('border-y border-ink bg-surface p-5 sm:p-6') }}>
    <div class="flex flex-col gap-5 border-b border-rule pb-5 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-muted">Detalhamento da evolução</p>
            <h2 class="public-section-title mt-1 text-3xl sm:text-4xl">
                O que mudou na gestão de {{ $explanation['mayor']['name'] ?? 'prefeito não identificado' }}
            </h2>
            <p class="mt-2 max-w-[68ch] text-sm leading-6 text-muted">
                {{ $explanation['municipality']['name'] }}/{{ $explanation['municipality']['federative_unit'] }}
                · nota {{ ($explanation['evolution_score'] ?? 0) > 0 ? '+' : '' }}{{ number_format($explanation['evolution_score'] ?? 0, 2, ',', '.') }}.
                A variação mostra o avanço ou a queda de posição relativa do município.
            </p>
        </div>
        <flux:button variant="ghost" wire:click="$set('selectedExplanation', null)" aria-label="Fechar detalhamento da gestão">
            Fechar
        </flux:button>
    </div>

    <dl class="grid grid-cols-2 gap-px bg-rule sm:grid-cols-4">
        <div class="bg-white p-4">
            <dt class="text-xs font-semibold text-muted">Melhoraram</dt>
            <dd class="public-numeral mt-1 text-3xl">{{ $summary['improved'] }}</dd>
        </div>
        <div class="bg-white p-4">
            <dt class="text-xs font-semibold text-muted">Pioraram</dt>
            <dd class="public-numeral mt-1 text-3xl">{{ $summary['declined'] }}</dd>
        </div>
        <div class="bg-white p-4">
            <dt class="text-xs font-semibold text-muted">Estáveis</dt>
            <dd class="public-numeral mt-1 text-3xl">{{ $summary['unchanged'] }}</dd>
        </div>
        <div class="bg-white p-4">
            <dt class="text-xs font-semibold text-muted">Sem dados comparáveis</dt>
            <dd class="public-numeral mt-1 text-3xl">{{ $summary['not_comparable'] }}</dd>
        </div>
    </dl>

    <div class="divide-y divide-rule border-b border-rule">
        @foreach ($explanation['components'] as $component)
            @php
                $change = $component['percentile_change'];
                $status = $change === null
                    ? 'not_comparable'
                    : ($change > 0 ? 'improved' : ($change < 0 ? 'declined' : 'unchanged'));
                $statusLabel = match ($status) {
                    'improved' => 'Melhorou',
                    'declined' => 'Piorou',
                    'unchanged' => 'Estável',
                    default => 'Sem dados',
                };
            @endphp

            <article wire:key="{{ $id }}-{{ $component['indicator'] }}" class="grid gap-4 bg-white py-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(8rem,1fr)_minmax(8rem,1fr)_minmax(8rem,0.8fr)] lg:items-center">
                <div class="px-4">
                    <span @class([
                        'inline-flex px-2 py-1 text-xs font-bold',
                        'bg-brand text-ink' => $status === 'improved',
                        'bg-ink text-white' => $status === 'declined',
                        'border border-ink text-ink' => $status === 'unchanged',
                        'bg-rule text-ink' => $status === 'not_comparable',
                    ])>
                        {{ $statusLabel }}
                    </span>
                    <h3 class="mt-2 font-bold">{{ $component['name'] }}</h3>
                    <p class="mt-1 text-xs text-muted">{{ $themeLabel($component['theme']) }}</p>
                </div>

                <div class="px-4">
                    <div class="text-xs font-semibold text-muted">Início · {{ $component['baseline']['effective_year'] ?? 'sem ano' }}</div>
                    <div class="public-tabular mt-1 font-bold">{{ $formatValue($component['baseline']['raw_value'], $component['unit']) }}</div>
                    <div class="mt-1 text-xs text-muted">
                        Percentil {{ $component['baseline']['percentile'] === null ? '—' : number_format($component['baseline']['percentile'], 1, ',', '.') }}
                    </div>
                </div>

                <div class="px-4">
                    <div class="text-xs font-semibold text-muted">Final · {{ $component['end']['effective_year'] ?? 'sem ano' }}</div>
                    <div class="public-tabular mt-1 font-bold">{{ $formatValue($component['end']['raw_value'], $component['unit']) }}</div>
                    <div class="mt-1 text-xs text-muted">
                        Percentil {{ $component['end']['percentile'] === null ? '—' : number_format($component['end']['percentile'], 1, ',', '.') }}
                    </div>
                </div>

                <div class="px-4">
                    <div class="text-xs font-semibold text-muted">Mudança relativa</div>
                    <div class="public-numeral mt-1 text-2xl">
                        {{ $change === null ? '—' : ($change > 0 ? '+' : '').number_format($change, 2, ',', '.').' p.p.' }}
                    </div>
                    <div class="mt-1 text-xs text-muted">
                        Contribuição {{ $component['contribution'] === null ? '—' : (($component['contribution'] > 0 ? '+' : '').number_format($component['contribution'], 2, ',', '.')) }}
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</section>
