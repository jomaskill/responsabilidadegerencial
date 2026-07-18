<?php

namespace App\MunicipalData\Fetchers;

use App\MunicipalData\SourceArtifact;
use App\MunicipalData\SourceFetcher;
use Illuminate\Support\Facades\Http;

class IbgeMunicipalityFetcher implements SourceFetcher
{
    public function fetch(int $referenceYear): SourceArtifact
    {
        $url = (string) config('municipal_data.sources.ibge_localities_url');
        $response = Http::acceptJson()
            ->timeout((int) config('municipal_data.http.timeout_seconds'))
            ->retry(
                (int) config('municipal_data.http.retry_times'),
                (int) config('municipal_data.http.retry_sleep_milliseconds'),
            )
            ->get($url)
            ->throw();

        return new SourceArtifact(
            contents: $response->body(),
            sourceUrl: $url,
            extension: 'json',
            mimeType: 'application/json',
        );
    }
}
