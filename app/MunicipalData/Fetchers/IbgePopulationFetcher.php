<?php

namespace App\MunicipalData\Fetchers;

use App\MunicipalData\PopulationFetcher;
use App\MunicipalData\PopulationSourceDefinition;
use App\MunicipalData\SourceArtifact;
use Illuminate\Support\Facades\Http;

class IbgePopulationFetcher implements PopulationFetcher
{
    public function fetch(PopulationSourceDefinition $definition): SourceArtifact
    {
        $response = Http::withHeaders([
            'Accept' => $definition->format === 'json' ? 'application/json' : 'application/vnd.oasis.opendocument.spreadsheet',
            'User-Agent' => config('app.name').'/municipal-data-importer',
        ])
            ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
            ->timeout((int) config('municipal_data.http.timeout_seconds'))
            ->retry(
                (int) config('municipal_data.http.retry_times'),
                (int) config('municipal_data.http.retry_sleep_milliseconds'),
            )
            ->get($definition->url)
            ->throw();

        return new SourceArtifact(
            contents: $response->body(),
            sourceUrl: $definition->url,
            extension: $definition->format,
            mimeType: $definition->format === 'json'
                ? 'application/json'
                : 'application/vnd.oasis.opendocument.spreadsheet',
            publishedAt: $definition->publishedAt,
        );
    }
}
