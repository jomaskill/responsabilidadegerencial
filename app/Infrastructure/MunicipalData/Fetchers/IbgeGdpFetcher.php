<?php

namespace App\Infrastructure\MunicipalData\Fetchers;

use App\Contracts\MunicipalData\GdpFetcher;
use App\DTO\MunicipalData\SourceArtifact;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;

class IbgeGdpFetcher implements GdpFetcher
{
    public function fetch(): SourceArtifact
    {
        $url = (string) config('municipal_data.gdp.url');
        $response = Http::withHeaders([
            'Accept' => 'application/zip',
            'User-Agent' => config('app.name').'/municipal-data-importer',
        ])
            ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
            ->timeout(max(120, (int) config('municipal_data.http.timeout_seconds')))
            ->retry(
                (int) config('municipal_data.http.retry_times'),
                (int) config('municipal_data.http.retry_sleep_milliseconds'),
            )
            ->get($url)
            ->throw();

        return new SourceArtifact(
            contents: $response->body(),
            sourceUrl: $url,
            extension: 'zip',
            mimeType: 'application/zip',
            publishedAt: new DateTimeImmutable('2025-12-19'),
        );
    }
}
