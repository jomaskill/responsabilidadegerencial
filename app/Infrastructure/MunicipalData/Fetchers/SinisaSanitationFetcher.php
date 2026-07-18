<?php

namespace App\Infrastructure\MunicipalData\Fetchers;

use App\Contracts\MunicipalData\SanitationFetcher;
use App\DTO\MunicipalData\SourceArtifact;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class SinisaSanitationFetcher implements SanitationFetcher
{
    private const ARCHIVE_TIMESTAMP = 315532800;

    public function fetch(): SourceArtifact
    {
        $datasets = config('municipal_data.sinisa.datasets');

        if (! is_array($datasets) || $datasets === []) {
            throw new RuntimeException('No SINISA datasets are configured.');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'sinisa-sources-');

        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to create the temporary SINISA archive.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the SINISA source archive.');
            }

            try {
                foreach ($datasets as $dataset) {
                    if (! is_array($dataset)) {
                        throw new RuntimeException('Invalid SINISA dataset configuration.');
                    }

                    $this->addConfiguredSource($archive, $dataset);

                    foreach ((array) ($dataset['corrections'] ?? []) as $correction) {
                        if (! is_array($correction)) {
                            throw new RuntimeException('Invalid SINISA correction configuration.');
                        }

                        $this->addConfiguredSource($archive, $correction);
                    }
                }
            } finally {
                $archive->close();
            }

            $contents = file_get_contents($temporaryFile);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the SINISA source archive.');
            }

            return new SourceArtifact(
                contents: $contents,
                sourceUrl: (string) config('municipal_data.sinisa.results_url'),
                extension: 'zip',
                mimeType: 'application/zip',
                publishedAt: new DateTimeImmutable((string) config('municipal_data.sinisa.published_at')),
            );
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @param array<string, mixed> $source */
    private function addConfiguredSource(ZipArchive $archive, array $source): void
    {
        $contents = $this->sourceContents($source);
        $expectedChecksum = $source['sha256'] ?? null;

        if (is_string($expectedChecksum) && ! hash_equals($expectedChecksum, hash('sha256', $contents))) {
            throw new RuntimeException('A SINISA package does not match its audited checksum.');
        }

        $entry = (string) $source['wrapper_entry'];

        if (! $archive->addFromString($entry, $contents)) {
            throw new RuntimeException('Unable to add an official file to the SINISA source archive.');
        }

        if (! $archive->setMtimeName($entry, self::ARCHIVE_TIMESTAMP)) {
            throw new RuntimeException('Unable to normalize the SINISA source archive timestamp.');
        }
    }

    /** @param array<string, mixed> $source */
    private function sourceContents(array $source): string
    {
        $localPath = $source['local_path'] ?? null;

        if (is_string($localPath) && $localPath !== '') {
            $contents = is_file($localPath) ? file_get_contents($localPath) : false;

            if ($contents === false) {
                throw new RuntimeException("Unable to read the local SINISA source: {$localPath}");
            }

            return $contents;
        }

        return Http::withHeaders([
            'Accept' => '*/*',
            'User-Agent' => config('app.name').'/municipal-data-importer',
        ])
            ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
            ->timeout(max(180, (int) config('municipal_data.http.timeout_seconds')))
            ->retry(
                (int) config('municipal_data.http.retry_times'),
                (int) config('municipal_data.http.retry_sleep_milliseconds'),
            )
            ->get((string) $source['url'])
            ->throw()
            ->body();
    }
}
