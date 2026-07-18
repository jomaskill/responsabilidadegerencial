<?php

namespace App\Infrastructure\MunicipalData\Fetchers;

use App\Contracts\MunicipalData\HomicideFetcher;
use App\DTO\MunicipalData\HomicideSourceDefinition;
use App\DTO\MunicipalData\SourceArtifact;
use Illuminate\Support\Facades\Http;

class DatasusHomicideFetcher implements HomicideFetcher
{
    public function fetch(HomicideSourceDefinition $definition): SourceArtifact
    {
        $response = Http::withHeaders([
            'Accept' => 'text/html',
            'User-Agent' => config('app.name').'/municipal-data-importer',
        ])
            ->withBody($this->requestBody($definition), 'application/x-www-form-urlencoded')
            ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
            ->timeout((int) config('municipal_data.http.timeout_seconds'))
            ->retry(
                (int) config('municipal_data.http.retry_times'),
                (int) config('municipal_data.http.retry_sleep_milliseconds'),
            )
            ->post($definition->url)
            ->throw();

        return new SourceArtifact(
            contents: $response->body(),
            sourceUrl: $definition->url,
            extension: 'html',
            mimeType: 'text/html',
            publishedAt: $definition->publishedAt,
        );
    }

    private function requestBody(HomicideSourceDefinition $definition): string
    {
        return implode('&', [
            'Linha=Munic%EDpio',
            'Coluna=--N%E3o-Ativa--',
            'Incremento=%D3bitos_p%2FResid%EAnc',
            'Arquivos='.rawurlencode($definition->file),
            'SMunic%EDpio=TODAS_AS_CATEGORIAS__',
            'SCausa_-_CID-BR-10=141',
            'SCausa_-_CID-BR-10=143',
            'formato=prn',
            'mostre=Mostra',
        ]);
    }
}
