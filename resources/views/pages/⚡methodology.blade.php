<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public'), Title('Metodologia')] class extends Component {
}; ?>

<article class="mx-auto grid max-w-4xl gap-12">
    <header class="border-b border-ink pb-8">
        <span class="inline-block bg-brand px-3 py-1 text-sm font-bold">Versão {{ config('municipal_ranking.methodology_version') }}</span>
        <h1 class="public-display mt-5 text-5xl sm:text-6xl">Como avaliamos as gestões de prefeitos</h1>
        <p class="mt-4 max-w-[68ch] text-lg leading-7 text-muted">
            Regras públicas, reproduzíveis e calculadas no momento da consulta. Nenhuma nota ou posição é persistida no banco.
        </p>
    </header>

    <section class="grid gap-4">
        <h2 class="public-section-title text-3xl">Exercício e fontes</h2>
        <p class="max-w-[68ch] text-base leading-7">
            Para cada indicador, usamos o último dado oficial com ano de referência menor ou igual ao exercício escolhido.
            O ano efetivamente utilizado, a release, a URL e o checksum aparecem na explicação da nota.
        </p>
    </section>

    <section class="grid gap-4">
        <h2 class="public-section-title text-3xl">Recorte do ranking</h2>
        <p class="max-w-[68ch] text-base leading-7">
            Os percentis são recalculados dentro do Brasil, da UF e/ou da faixa populacional filtrada.
            Indicadores em que um valor menor é melhor, como homicídios, têm a direção invertida.
        </p>
    </section>

    <section class="grid gap-4">
        <h2 class="public-section-title text-3xl">Pesos e dados ausentes</h2>
        <p class="max-w-[68ch] text-base leading-7">
            O perfil padrão divide 25% entre economia, educação, saneamento e segurança. Antes de aparecer publicamente,
            cada indicador precisa ter valor utilizável para todos os municípios existentes no ano da fonte.
            Se a cobertura nacional estiver incompleta, o indicador inteiro fica oculto e não entra em nenhuma nota.
        </p>
        <p class="border-y border-ink bg-brand px-5 py-4 font-bold">
            Ausência, supressão ou dado ainda não publicado nunca são convertidos em zero nem exibidos apenas para alguns municípios.
        </p>
    </section>

    <section class="grid gap-4">
        <h2 class="public-section-title text-3xl">Empates</h2>
        <p class="max-w-[68ch] text-base leading-7">
            Valores empatados recebem o mesmo percentil. A posição final segue competição:
            <strong class="public-tabular">1, 2, 2, 4</strong>.
        </p>
    </section>

    <section id="gestoes" class="grid gap-5 border-t-4 border-brand bg-surface p-6 sm:p-8">
        <h2 class="public-section-title text-4xl">Evolução das gestões de prefeitos</h2>
        <p class="max-w-[68ch] text-base leading-7">
            O mandato eleito em 2016 usa os exercícios 2017 e 2020; o eleito em 2020 usa 2021 e 2024.
            O mandato eleito em 2024 usa 2024 e o último exercício aberto até 2025. Cada componente mede a diferença
            entre o percentil final e o percentil inicial.
        </p>
        <ul class="grid gap-3 text-base leading-6">
            <li class="flex gap-3"><span class="public-numeral">01</span><span>O indicador só participa se seu ano efetivo avançou entre os dois pontos.</span></li>
            <li class="flex gap-3"><span class="public-numeral">02</span><span>Os dois pontos também precisam ter cobertura nacional completa; caso contrário, o indicador é ocultado para todos.</span></li>
            <li class="flex gap-3"><span class="public-numeral">03</span><span>A nota usa somente os indicadores completos que avançaram, com os pesos redistribuídos entre eles.</span></li>
        </ul>
        <p class="max-w-[68ch] text-sm leading-6 text-muted">
            O resultado descreve a evolução relativa do município durante a gestão. Não estabelece que o prefeito causou a mudança.
            O MVP inclui apenas vencedores da eleição geral; substituições e eleições suplementares estão fora desta versão.
        </p>
    </section>

    <section class="border-y border-ink py-6">
        <h2 class="public-section-title text-3xl">Dados para auditoria</h2>
        <div class="mt-5 flex flex-wrap gap-3">
            <flux:button :href="route('public.open-data')">Ver indicadores e fontes</flux:button>
        </div>
    </section>
</article>
