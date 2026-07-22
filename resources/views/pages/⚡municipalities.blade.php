<?php

use App\Models\Municipality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Municípios')] class extends Component {
    #[Url(history: true)]
    public string $search = '';

    #[Url]
    public string $uf = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 40;

    public function applyFilters(): void
    {
        $this->page = 1;
        unset($this->directory);
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
        unset($this->directory);
    }

    public function nextPage(): void
    {
        $this->page = min($this->directory['last_page'], $this->page + 1);
        unset($this->directory);
    }

    #[Computed]
    public function directory(): array
    {
        $query = Municipality::query()
            ->with('federativeUnit')
            ->existingInYear(2025)
            ->when($this->uf !== '', fn (Builder $builder) => $builder->whereHas(
                'federativeUnit',
                fn (Builder $unit) => $unit->where('acronym', mb_strtoupper($this->uf)),
            ))
            ->when($this->search !== '', function (Builder $builder): void {
                $search = Str::ascii(trim($this->search));
                $builder->where(function (Builder $municipalities) use ($search): void {
                    $municipalities
                        ->where('ibge_code', 'like', "%{$search}%")
                        ->orWhere('normalized_name', 'like', '%'.mb_strtolower($search).'%');
                });
            })
            ->orderBy('normalized_name');
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $this->perPage));
        $this->page = min($this->page, $lastPage);

        return [
            'rows' => $query
                ->forPage($this->page, $this->perPage)
                ->get()
                ->map(fn (Municipality $municipality): array => [
                    'ibge_code' => $municipality->ibge_code,
                    'name' => $municipality->name,
                    'uf' => $municipality->federativeUnit->acronym,
                ])
                ->all(),
            'total' => $total,
            'last_page' => $lastPage,
        ];
    }
}; ?>

<div class="grid gap-10">
    <header class="border-b border-ink pb-8">
        <h1 class="public-display text-5xl sm:text-6xl">Municípios</h1>
        <p class="mt-4 max-w-[68ch] text-lg leading-7 text-muted">
            Encontre uma cidade para consultar sua posição, a evolução dos indicadores e as fontes utilizadas.
        </p>
    </header>

    <form wire:submit="applyFilters" class="grid gap-4 bg-surface p-5 sm:grid-cols-[1fr_8rem_auto] sm:items-end">
        <flux:input wire:model="search" label="Nome ou código IBGE" icon="magnifying-glass" placeholder="Ex.: Campinas ou 3509502" />
        <flux:input wire:model="uf" label="UF" maxlength="2" placeholder="SP" />
        <flux:button type="submit" variant="primary">Buscar</flux:button>
    </form>

    <div wire:loading class="bg-brand px-4 py-3 font-semibold">Buscando municípios…</div>

    <section wire:loading.remove class="border-y border-ink">
        <div class="bg-surface px-4 py-4 font-semibold">
            {{ number_format($this->directory['total'], 0, ',', '.') }} municípios encontrados
        </div>
        <ul class="grid divide-y divide-rule md:grid-cols-2 md:divide-x">
            @forelse ($this->directory['rows'] as $municipality)
                <li wire:key="municipality-{{ $municipality['ibge_code'] }}" class="flex items-center justify-between gap-4 p-4 md:[&:nth-child(odd)]:border-r md:[&:nth-child(odd)]:border-rule">
                    <div>
                        <a href="{{ route('public.municipality', $municipality['ibge_code']) }}" class="public-link font-semibold" wire:navigate>
                            {{ $municipality['name'] }}/{{ $municipality['uf'] }}
                        </a>
                        <div class="public-tabular text-sm text-muted">{{ $municipality['ibge_code'] }}</div>
                    </div>
                    <flux:icon.arrow-right class="size-4" />
                </li>
            @empty
                <li class="p-10 text-center text-muted md:col-span-2">Nenhum município encontrado.</li>
            @endforelse
        </ul>
        <div class="flex items-center justify-between gap-4 border-t border-rule px-4 py-4">
            <flux:button wire:click="previousPage" :disabled="$page <= 1">Anterior</flux:button>
            <span class="text-sm">Página {{ $page }} de {{ $this->directory['last_page'] }}</span>
            <flux:button wire:click="nextPage" :disabled="$page >= $this->directory['last_page']">Próxima</flux:button>
        </div>
    </section>
</div>
