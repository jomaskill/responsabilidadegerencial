<?php

namespace App\MunicipalData\Parsers;

use Generator;
use RuntimeException;
use ZipArchive;

class GdpArchiveParser
{
    /** @return Generator<int, array{year: int, municipality_code: string, gdp_thousand_reais: string, gdp_per_capita_reais: string}> */
    public function rows(string $contents): Generator
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'gdp-archive-');

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to create the temporary GDP archive.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryFile) !== true) {
                throw new RuntimeException('The downloaded GDP artifact is not a valid ZIP file.');
            }

            try {
                $entry = (string) config('municipal_data.gdp.entry');
                $stat = $archive->statName($entry);

                if ($stat === false || (int) $stat['size'] > 150_000_000) {
                    throw new RuntimeException('The GDP text entry is missing or exceeds the safe size limit.');
                }

                $stream = $archive->getStream($entry);

                if ($stream === false) {
                    throw new RuntimeException('Unable to open the GDP text entry.');
                }

                try {
                    $rowNumber = 0;

                    while (($line = fgets($stream)) !== false) {
                        $rowNumber++;

                        if ($rowNumber > 100_000) {
                            throw new RuntimeException('The GDP archive exceeds the safe row limit.');
                        }

                        if (strlen($line) < 972) {
                            throw new RuntimeException("Invalid fixed-width GDP row at line {$rowNumber}.");
                        }

                        $year = trim(substr($line, 0, 4));
                        $municipalityCode = trim(substr($line, 46, 7));
                        $gdp = trim(substr($line, 934, 19));
                        $gdpPerCapita = trim(substr($line, 953, 19));

                        if (
                            preg_match('/^\d{4}$/', $year) !== 1
                            || preg_match('/^\d{7}$/', $municipalityCode) !== 1
                            || preg_match('/^-?\d+(?:\.\d{3})?$/', $gdp) !== 1
                            || preg_match('/^-?\d+(?:\.\d{2})?$/', $gdpPerCapita) !== 1
                        ) {
                            throw new RuntimeException("Invalid GDP values at line {$rowNumber}.");
                        }

                        yield $rowNumber => [
                            'year' => (int) $year,
                            'municipality_code' => $municipalityCode,
                            'gdp_thousand_reais' => $gdp,
                            'gdp_per_capita_reais' => $gdpPerCapita,
                        ];
                    }
                } finally {
                    fclose($stream);
                }
            } finally {
                $archive->close();
            }
        } finally {
            @unlink($temporaryFile);
        }
    }
}
