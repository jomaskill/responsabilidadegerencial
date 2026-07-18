<?php

namespace App\MunicipalData\Fetchers;

use App\MunicipalData\IdebFetcher;
use App\MunicipalData\SourceArtifact;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class InepIdebFetcher implements IdebFetcher
{
    public function fetch(): SourceArtifact
    {
        $datasets = config('municipal_data.ideb.datasets');

        if (! is_array($datasets) || $datasets === []) {
            throw new RuntimeException('No IDEB datasets are configured.');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'ideb-sources-');

        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to create the temporary IDEB archive.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the IDEB source archive.');
            }

            try {
                foreach ($datasets as $dataset) {
                    if (! is_array($dataset)) {
                        throw new RuntimeException('Invalid IDEB dataset configuration.');
                    }

                    $contents = $this->datasetContents($dataset);
                    $expectedChecksum = $dataset['sha256'] ?? null;

                    if (is_string($expectedChecksum) && ! hash_equals($expectedChecksum, hash('sha256', $contents))) {
                        throw new RuntimeException('The downloaded IDEB package does not match its audited checksum.');
                    }

                    if (! $archive->addFromString((string) $dataset['wrapper_entry'], $contents)) {
                        throw new RuntimeException('Unable to add an official workbook to the IDEB archive.');
                    }
                }
            } finally {
                $archive->close();
            }

            $contents = file_get_contents($temporaryFile);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the IDEB source archive.');
            }

            return new SourceArtifact(
                contents: $contents,
                sourceUrl: 'https://www.gov.br/inep/pt-br/areas-de-atuacao/pesquisas-estatisticas-e-indicadores/ideb/resultados',
                extension: 'zip',
                mimeType: 'application/zip',
                publishedAt: new DateTimeImmutable((string) config('municipal_data.ideb.published_at')),
            );
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @param array<string, mixed> $dataset */
    private function datasetContents(array $dataset): string
    {
        $localPath = $dataset['local_path'] ?? null;

        if (is_string($localPath) && $localPath !== '') {
            $contents = is_file($localPath) ? file_get_contents($localPath) : false;

            if ($contents === false) {
                throw new RuntimeException("Unable to read the local IDEB source package: {$localPath}");
            }

            return $contents;
        }

        return Http::withHeaders([
            'Accept' => 'application/zip',
            'User-Agent' => config('app.name').'/municipal-data-importer',
        ])
            ->connectTimeout((int) config('municipal_data.http.connect_timeout_seconds'))
            ->timeout(max(180, (int) config('municipal_data.http.timeout_seconds')))
            ->retry(
                (int) config('municipal_data.http.retry_times'),
                (int) config('municipal_data.http.retry_sleep_milliseconds'),
            )
            ->get((string) $dataset['url'])
            ->throw()
            ->body();
    }
}
