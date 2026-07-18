<?php

namespace App\MunicipalData\Parsers;

use RuntimeException;
use ZipArchive;

class SinisaSanitationArchiveParser
{
    public function __construct(private readonly OpenXmlWorksheetReader $worksheetReader) {}

    /**
     * @return array{
     *     records: array<string, array<int, array{raw_value: string, source_layer: string}>>,
     *     input_rows: int
     * }
     */
    public function datasets(string $contents): array
    {
        $temporaryFile = $this->temporaryFile('sinisa-wrapper-', $contents);

        try {
            $wrapper = new ZipArchive;

            if ($wrapper->open($temporaryFile) !== true) {
                throw new RuntimeException('The SINISA artifact is not a valid ZIP file.');
            }

            try {
                $configuredDatasets = config('municipal_data.sinisa.datasets');

                if (! is_array($configuredDatasets) || $configuredDatasets === []) {
                    throw new RuntimeException('No SINISA datasets are configured.');
                }

                $datasets = [];
                $inputRows = 0;

                foreach ($configuredDatasets as $slug => $configuration) {
                    if (! is_array($configuration)) {
                        throw new RuntimeException("Invalid SINISA dataset configuration: {$slug}");
                    }

                    $xlsx = $this->xlsxFromNestedArchive(
                        $wrapper,
                        (string) $configuration['wrapper_entry'],
                        (string) $configuration['xlsx_entry'],
                    );
                    $records = $this->records(
                        $xlsx,
                        (string) $configuration['indicator_code'],
                        'base',
                    );
                    $expectedRows = (int) $configuration['expected_source_rows'];

                    if (count($records) !== $expectedRows) {
                        throw new RuntimeException("SINISA source coverage failed for {$slug}: expected {$expectedRows}, found ".count($records).'.');
                    }

                    $inputRows += count($records);

                    foreach ((array) ($configuration['corrections'] ?? []) as $index => $correction) {
                        if (! is_array($correction)) {
                            throw new RuntimeException("Invalid SINISA correction configuration: {$slug}");
                        }

                        $correctionRecords = $this->records(
                            $this->wrapperEntry($wrapper, (string) $correction['wrapper_entry']),
                            (string) $configuration['indicator_code'],
                            'correction_'.($index + 1),
                        );
                        $expectedCorrectionRows = (int) $correction['expected_source_rows'];

                        if (count($correctionRecords) !== $expectedCorrectionRows) {
                            throw new RuntimeException("SINISA correction coverage failed for {$slug}: expected {$expectedCorrectionRows}, found ".count($correctionRecords).'.');
                        }

                        $inputRows += count($correctionRecords);

                        foreach ($correctionRecords as $municipalityCode => $correctionRecord) {
                            if (! isset($records[$municipalityCode]) || $records[$municipalityCode]['raw_value'] !== $correctionRecord['raw_value']) {
                                $records[$municipalityCode] = $correctionRecord;
                            }
                        }
                    }

                    $datasets[(string) $slug] = $records;
                }

                return ['records' => $datasets, 'input_rows' => $inputRows];
            } finally {
                $wrapper->close();
            }
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @return array<int, array{raw_value: string, source_layer: string}> */
    private function records(string $xlsx, string $indicatorCode, string $sourceLayer): array
    {
        $records = [];

        foreach ($this->worksheetReader->rows($xlsx) as $row) {
            $code = $row['cod_IBGE'] ?? '';

            if (preg_match('/^\d{7}$/', $code) !== 1) {
                continue;
            }

            if (! array_key_exists($indicatorCode, $row)) {
                throw new RuntimeException("A SINISA workbook is missing indicator {$indicatorCode}.");
            }

            $municipalityCode = (int) $code;

            if (isset($records[$municipalityCode])) {
                throw new RuntimeException("Duplicate SINISA municipality for {$indicatorCode}: {$code}");
            }

            $records[$municipalityCode] = [
                'raw_value' => $row[$indicatorCode],
                'source_layer' => $sourceLayer,
            ];
        }

        return $records;
    }

    private function xlsxFromNestedArchive(ZipArchive $wrapper, string $wrapperEntry, string $xlsxEntry): string
    {
        $outerContents = $this->wrapperEntry($wrapper, $wrapperEntry);
        $outerFile = $this->temporaryFile('sinisa-outer-', $outerContents);

        try {
            $archive = new ZipArchive;

            if ($archive->open($outerFile) !== true) {
                throw new RuntimeException("The SINISA package {$wrapperEntry} is not a valid ZIP file.");
            }

            try {
                $stat = $archive->statName($xlsxEntry);

                if ($stat === false || (int) $stat['size'] > 50_000_000) {
                    throw new RuntimeException("The SINISA workbook {$xlsxEntry} is missing or exceeds the safe size limit.");
                }

                $contents = $archive->getFromName($xlsxEntry);

                if ($contents === false) {
                    throw new RuntimeException("Unable to read the SINISA workbook {$xlsxEntry}.");
                }

                return $contents;
            } finally {
                $archive->close();
            }
        } finally {
            @unlink($outerFile);
        }
    }

    private function wrapperEntry(ZipArchive $wrapper, string $entry): string
    {
        $stat = $wrapper->statName($entry);

        if ($stat === false || (int) $stat['size'] > 50_000_000) {
            throw new RuntimeException("The SINISA source {$entry} is missing or exceeds the safe size limit.");
        }

        $contents = $wrapper->getFromName($entry);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the SINISA source {$entry}.");
        }

        return $contents;
    }

    private function temporaryFile(string $prefix, string $contents): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to create a temporary SINISA file.');
        }

        return $temporaryFile;
    }
}
