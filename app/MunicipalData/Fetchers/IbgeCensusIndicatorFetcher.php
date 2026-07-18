<?php

namespace App\MunicipalData\Fetchers;

use App\Contracts\MunicipalData\CensusIndicatorFetcher;
use App\DTO\MunicipalData\SourceArtifact;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class IbgeCensusIndicatorFetcher implements CensusIndicatorFetcher
{
    public function fetch(): SourceArtifact
    {
        $datasets = config('municipal_data.census_indicators.datasets');

        if (! is_array($datasets) || $datasets === []) {
            throw new RuntimeException('No Census indicator datasets are configured.');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'census-indicators-');

        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to create the temporary Census archive.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the Census source archive.');
            }

            try {
                foreach ($datasets as $dataset) {
                    if (! is_array($dataset)) {
                        throw new RuntimeException('Invalid Census indicator configuration.');
                    }

                    $url = (string) $dataset['url'];
                    $response = Http::withHeaders([
                        'Accept' => 'application/json',
                        'User-Agent' => config('app.name').'/municipal-data-importer',
                    ])
                        ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
                        ->timeout((int) config('municipal_data.http.timeout_seconds'))
                        ->retry(
                            (int) config('municipal_data.http.retry_times'),
                            (int) config('municipal_data.http.retry_sleep_milliseconds'),
                        )
                        ->get($url)
                        ->throw();

                    if (! $archive->addFromString((string) $dataset['entry'], $response->body())) {
                        throw new RuntimeException('Unable to add an official response to the Census archive.');
                    }
                }
            } finally {
                $archive->close();
            }

            $contents = file_get_contents($temporaryFile);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the Census source archive.');
            }

            return new SourceArtifact(
                contents: $contents,
                sourceUrl: 'https://sidra.ibge.gov.br/pesquisa/censo-demografico/demografico-2022',
                extension: 'zip',
                mimeType: 'application/zip',
                publishedAt: new DateTimeImmutable((string) config('municipal_data.census_indicators.published_at')),
            );
        } finally {
            @unlink($temporaryFile);
        }
    }
}
