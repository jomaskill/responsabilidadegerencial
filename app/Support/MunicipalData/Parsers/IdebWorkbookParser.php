<?php

namespace App\Support\MunicipalData\Parsers;

use DOMDocument;
use RuntimeException;
use XMLReader;
use ZipArchive;

class IdebWorkbookParser
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @return array<string, array<int, array{municipality_code: int, values: array<int, string>}>> */
    public function datasets(string $contents): array
    {
        $temporaryFile = $this->temporaryFile('ideb-wrapper-', $contents);

        try {
            $wrapper = new ZipArchive;

            if ($wrapper->open($temporaryFile) !== true) {
                throw new RuntimeException('The IDEB artifact is not a valid ZIP file.');
            }

            try {
                $configured = config('municipal_data.ideb.datasets');

                if (! is_array($configured)) {
                    throw new RuntimeException('No IDEB datasets are configured.');
                }

                $datasets = [];

                foreach ($configured as $slug => $dataset) {
                    if (! is_array($dataset)) {
                        throw new RuntimeException('Invalid IDEB dataset configuration.');
                    }

                    $entry = (string) $dataset['wrapper_entry'];
                    $stat = $wrapper->statName($entry);

                    if ($stat === false || (int) $stat['size'] > 40_000_000) {
                        throw new RuntimeException("IDEB source {$entry} is missing or exceeds the safe size limit.");
                    }

                    $outerContents = $wrapper->getFromName($entry);

                    if ($outerContents === false) {
                        throw new RuntimeException("Unable to read IDEB source {$entry}.");
                    }

                    $datasets[(string) $slug] = $this->workbookRows(
                        $outerContents,
                        (string) $dataset['xlsx_entry'],
                    );
                }

                return $datasets;
            } finally {
                $wrapper->close();
            }
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @return array<int, array{municipality_code: int, values: array<int, string>}> */
    private function workbookRows(string $outerContents, string $xlsxEntry): array
    {
        $outerFile = $this->temporaryFile('ideb-outer-', $outerContents);

        try {
            $outerArchive = new ZipArchive;

            if ($outerArchive->open($outerFile) !== true) {
                throw new RuntimeException('An IDEB source package is not a valid ZIP file.');
            }

            try {
                $stat = $outerArchive->statName($xlsxEntry);

                if ($stat === false || (int) $stat['size'] > 50_000_000) {
                    throw new RuntimeException('The IDEB workbook is missing or exceeds the safe size limit.');
                }

                $xlsxContents = $outerArchive->getFromName($xlsxEntry);
            } finally {
                $outerArchive->close();
            }

            if ($xlsxContents === false) {
                throw new RuntimeException('Unable to read the IDEB workbook.');
            }

            $xlsxFile = $this->temporaryFile('ideb-xlsx-', $xlsxContents);

            try {
                return $this->rowsFromXlsx($xlsxFile);
            } finally {
                @unlink($xlsxFile);
            }
        } finally {
            @unlink($outerFile);
        }
    }

    /** @return array<int, array{municipality_code: int, values: array<int, string>}> */
    private function rowsFromXlsx(string $xlsxFile): array
    {
        $archive = new ZipArchive;

        if ($archive->open($xlsxFile) !== true) {
            throw new RuntimeException('The IDEB workbook is not a valid XLSX file.');
        }

        try {
            $sharedStrings = $this->sharedStrings($archive);

            if ($archive->locateName('xl/worksheets/sheet1.xml') === false) {
                throw new RuntimeException('The IDEB workbook does not contain its expected worksheet.');
            }
        } finally {
            $archive->close();
        }

        $reader = new XMLReader;
        $worksheetUrl = 'zip://'.str_replace('\\', '/', $xlsxFile).'#xl/worksheets/sheet1.xml';

        if (! $reader->open($worksheetUrl, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to stream the IDEB worksheet.');
        }

        try {
            $requiredColumns = null;
            $records = [];
            $rowCount = 0;

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowCount++;

                if ($rowCount > 20_000) {
                    throw new RuntimeException('The IDEB worksheet exceeds the safe row limit.');
                }

                $rowNumber = (int) $reader->getAttribute('r');

                if ($rowNumber < 10) {
                    continue;
                }

                $values = $this->rowValues($reader->readOuterXml(), $sharedStrings);

                if ($rowNumber === 10) {
                    $requiredColumns = $this->requiredColumns($values);

                    continue;
                }

                if ($requiredColumns === null) {
                    throw new RuntimeException('The IDEB workbook header was not found.');
                }

                if (($values[$requiredColumns['REDE']] ?? '') !== 'Municipal') {
                    continue;
                }

                $code = $values[$requiredColumns['CO_MUNICIPIO']] ?? '';

                if (preg_match('/^\d{7}$/', $code) !== 1) {
                    throw new RuntimeException("Invalid municipality code in IDEB row {$rowNumber}.");
                }

                $cycleValues = [];

                foreach ((array) config('municipal_data.ideb.cycles', []) as $cycle) {
                    $year = (int) $cycle;
                    $cycleValues[$year] = $values[$requiredColumns["VL_OBSERVADO_{$year}"]] ?? '-';
                }

                $records[] = [
                    'municipality_code' => (int) $code,
                    'values' => $cycleValues,
                ];
            }

            return $records;
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, int>
     */
    private function requiredColumns(array $values): array
    {
        $headers = array_flip($values);

        $requiredHeaders = ['CO_MUNICIPIO', 'REDE'];

        foreach ((array) config('municipal_data.ideb.cycles', []) as $cycle) {
            $requiredHeaders[] = 'VL_OBSERVADO_'.(int) $cycle;
        }

        foreach ($requiredHeaders as $header) {
            if (! isset($headers[$header])) {
                throw new RuntimeException("The IDEB workbook is missing column {$header}.");
            }
        }

        return array_map(
            fn (string $header): int => (int) $headers[$header],
            array_combine($requiredHeaders, $requiredHeaders),
        );
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $archive): array
    {
        $contents = $archive->getFromName('xl/sharedStrings.xml');

        if ($contents === false || strlen($contents) > 5_000_000) {
            throw new RuntimeException('The IDEB shared string table is missing or exceeds the safe size limit.');
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to parse the IDEB shared string table.');
        }

        $strings = [];

        foreach ($document->getElementsByTagNameNS(self::SPREADSHEET_NAMESPACE, 'si') as $node) {
            $strings[] = $node->textContent;
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, string>
     */
    private function rowValues(string $rowXml, array $sharedStrings): array
    {
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        if (! $document->loadXML($rowXml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to parse an IDEB worksheet row.');
        }

        $values = [];

        foreach ($document->getElementsByTagNameNS(self::SPREADSHEET_NAMESPACE, 'c') as $cell) {
            $reference = $cell->getAttribute('r');

            if (preg_match('/^([A-Z]+)\d+$/', $reference, $match) !== 1) {
                continue;
            }

            $column = $this->columnIndex($match[1]);
            $valueNodes = $cell->getElementsByTagNameNS(self::SPREADSHEET_NAMESPACE, 'v');
            $rawValue = $valueNodes->length === 0 ? '' : $valueNodes->item(0)->textContent;

            $values[$column] = match ($cell->getAttribute('t')) {
                's' => $sharedStrings[(int) $rawValue] ?? '',
                'inlineStr' => $cell->textContent,
                default => $rawValue,
            };
        }

        return $values;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function temporaryFile(string $prefix, string $contents): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to create a temporary IDEB file.');
        }

        return $temporaryFile;
    }
}
