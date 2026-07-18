<?php

namespace App\Support\MunicipalData\Parsers;

use RuntimeException;
use ZipArchive;

class CensusIndicatorArchiveParser
{
    /** @return array<string, string> */
    public function datasets(string $contents): array
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'census-parser-');

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to create the temporary Census artifact.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryFile) !== true) {
                throw new RuntimeException('The Census artifact is not a valid ZIP file.');
            }

            try {
                $configured = config('municipal_data.census_indicators.datasets');

                if (! is_array($configured)) {
                    throw new RuntimeException('No Census indicator datasets are configured.');
                }

                $datasets = [];

                foreach ($configured as $slug => $dataset) {
                    if (! is_array($dataset)) {
                        throw new RuntimeException('Invalid Census indicator configuration.');
                    }

                    $entry = (string) $dataset['entry'];
                    $stat = $archive->statName($entry);

                    if ($stat === false || (int) $stat['size'] > 20_000_000) {
                        throw new RuntimeException("Census dataset {$entry} is missing or exceeds the safe size limit.");
                    }

                    $datasetContents = $archive->getFromName($entry);

                    if ($datasetContents === false) {
                        throw new RuntimeException("Unable to read Census dataset {$entry}.");
                    }

                    $datasets[(string) $slug] = $datasetContents;
                }

                return $datasets;
            } finally {
                $archive->close();
            }
        } finally {
            @unlink($temporaryFile);
        }
    }
}
