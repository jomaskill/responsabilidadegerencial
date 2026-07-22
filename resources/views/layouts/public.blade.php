<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        @include('partials.head', ['forceLight' => true])
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <a href="#conteudo" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:bg-brand focus:px-4 focus:py-3 focus:font-semibold focus:text-ink">
            Pular para o conteúdo
        </a>

        <header x-data="{ open: false }" class="border-b border-rule bg-white">
            <div class="h-1.5 bg-brand"></div>
            <div class="mx-auto flex max-w-7xl items-center gap-5 px-4 py-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="font-display text-2xl font-bold leading-none tracking-[-0.02em]" wire:navigate>
                    Responsabilidade Gerencial
                </a>

                <nav aria-label="Navegação principal" class="ml-auto hidden items-center gap-5 text-sm font-semibold lg:flex">
                    <a href="{{ route('home') }}" class="public-link" wire:navigate>Início</a>
                    <a href="{{ route('public.mayors') }}" class="public-link" wire:navigate>Gestões de prefeitos</a>
                    <a href="{{ route('public.municipalities') }}" class="public-link" wire:navigate>Municípios</a>
                    <a href="{{ route('public.ranking') }}" class="public-link" wire:navigate>Indicadores</a>
                    <a href="{{ route('public.methodology') }}" class="public-link" wire:navigate>Metodologia</a>
                    <a href="{{ route('public.open-data') }}" class="public-link" wire:navigate>Dados e fontes</a>
                </nav>

                <a href="{{ route('public.municipalities') }}" class="ml-auto hidden items-center gap-2 border border-ink px-3 py-2 text-sm font-semibold hover:bg-surface lg:ml-0 lg:flex" wire:navigate>
                    <flux:icon.magnifying-glass class="size-4" />
                    Buscar
                </a>

                <button
                    type="button"
                    class="ml-auto inline-flex size-10 items-center justify-center border border-ink lg:hidden"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open"
                    aria-controls="navegacao-movel"
                    aria-label="Abrir menu"
                >
                    <flux:icon.bars-3 x-show="! open" class="size-5" />
                    <flux:icon.x-mark x-show="open" x-cloak class="size-5" />
                </button>
            </div>

            <nav
                id="navegacao-movel"
                x-show="open"
                x-cloak
                x-on:keydown.escape.window="open = false"
                aria-label="Navegação móvel"
                class="border-t border-rule px-4 py-4 lg:hidden"
            >
                <div class="mx-auto grid max-w-7xl gap-1">
                    @foreach ([
                        ['home', 'Início'],
                        ['public.mayors', 'Gestões de prefeitos'],
                        ['public.municipalities', 'Municípios'],
                        ['public.ranking', 'Indicadores'],
                        ['public.methodology', 'Metodologia'],
                        ['public.open-data', 'Dados e fontes'],
                    ] as [$routeName, $label])
                        <a href="{{ route($routeName) }}" class="border-b border-rule py-3 font-semibold" wire:navigate>{{ $label }}</a>
                    @endforeach
                </div>
            </nav>
        </header>

        <main id="conteudo" class="mx-auto min-h-[60vh] max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
            {{ $slot }}
        </main>

        <footer class="mt-16 border-t border-ink bg-surface">
            <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-[1.5fr_1fr_1fr] lg:px-8">
                <div class="max-w-[65ch]">
                    <div class="font-display text-2xl font-bold">Responsabilidade Gerencial</div>
                    <p class="mt-3 text-base text-muted">
                        Dados oficiais, metodologia explícita e histórico preservado para acompanhar gestões de prefeitos.
                    </p>
                </div>
                <div class="grid content-start gap-2 text-sm">
                    <strong>Explore</strong>
                    <a class="public-link w-fit" href="{{ route('public.mayors') }}">Gestões de prefeitos</a>
                    <a class="public-link w-fit" href="{{ route('public.municipalities') }}">Municípios</a>
                    <a class="public-link w-fit" href="{{ route('public.ranking') }}">Indicadores municipais</a>
                </div>
                <div class="grid content-start gap-2 text-sm">
                    <strong>Transparência</strong>
                    <a class="public-link w-fit" href="{{ route('public.methodology') }}">Metodologia</a>
                    <a class="public-link w-fit" href="{{ route('public.open-data') }}">Dados e fontes</a>
                </div>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
