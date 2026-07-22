<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    @php($applicationName = ($forceLight ?? false) ? 'Responsabilidade Gerencial' : config('app.name', 'Responsabilidade Gerencial'))
    {{ filled($title ?? null) ? ($title === $applicationName ? $title : $title.' - '.$applicationName) : $applicationName }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@unless ($forceLight ?? false)
    @fonts
@endunless

@vite(['resources/css/app.css', 'resources/js/app.js'])
@unless ($forceLight ?? false)
    @fluxAppearance
@endunless
