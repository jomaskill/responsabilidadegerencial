<?php

namespace App\Infrastructure\MunicipalData\Fetchers;

use App\Contracts\MunicipalData\TseElectionFetcher;
use App\DTO\MunicipalData\SourceArtifact;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TseOpenDataFetcher implements TseElectionFetcher
{
    public function candidates(int $electionYear): SourceArtifact
    {
        $configuration = config("municipal_data.tse.elections.{$electionYear}");

        if (! is_array($configuration)) {
            throw new RuntimeException("TSE election {$electionYear} is not configured.");
        }

        return $this->artifact(
            configuration: $configuration,
            sourceUrl: (string) $configuration['url'],
        );
    }

    public function municipalityCodes(): SourceArtifact
    {
        $configuration = config('municipal_data.tse.municipality_codes');

        if (! is_array($configuration)) {
            throw new RuntimeException('The TSE–IBGE municipality correspondence is not configured.');
        }

        return $this->artifact(
            configuration: $configuration,
            sourceUrl: (string) $configuration['url'],
        );
    }

    /** @param array<string, mixed> $configuration */
    private function artifact(array $configuration, string $sourceUrl): SourceArtifact
    {
        $localPath = $configuration['local_path'] ?? null;

        if (is_string($localPath) && $localPath !== '') {
            $contents = is_file($localPath) ? file_get_contents($localPath) : false;

            if ($contents === false) {
                throw new RuntimeException("Unable to read the local TSE source package: {$localPath}");
            }
        } else {
            $contents = Http::withHeaders([
                'Accept' => 'application/zip',
                'User-Agent' => config('app.name').'/municipal-data-importer',
            ])
                ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
                ->timeout(max(180, (int) config('municipal_data.http.timeout_seconds')))
                ->retry(
                    (int) config('municipal_data.http.retry_times'),
                    (int) config('municipal_data.http.retry_sleep_milliseconds'),
                )
                ->get($sourceUrl)
                ->throw()
                ->body();
        }

        return new SourceArtifact(
            contents: $contents,
            sourceUrl: $sourceUrl,
            extension: 'zip',
            mimeType: 'application/zip',
            publishedAt: isset($configuration['published_at'])
                ? new DateTimeImmutable((string) $configuration['published_at'])
                : null,
        );
    }
}
