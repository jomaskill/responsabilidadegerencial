<?php

namespace App\MunicipalData\Parsers;

use DOMDocument;
use RuntimeException;
use XMLReader;
use ZipArchive;

class OpenXmlWorksheetReader
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @return list<array<string, string>> */
    public function rows(string $contents, int $headerRow = 10): array
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'xlsx-reader-');

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to create a temporary XLSX file.');
        }

        try {
            return $this->readRows($temporaryFile, $headerRow);
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @return list<array<string, string>> */
    private function readRows(string $xlsxFile, int $headerRow): array
    {
        $archive = new ZipArchive;

        if ($archive->open($xlsxFile) !== true) {
            throw new RuntimeException('A SINISA workbook is not a valid XLSX file.');
        }

        try {
            $sharedStrings = $this->sharedStrings($archive);

            if ($archive->locateName('xl/worksheets/sheet1.xml') === false) {
                throw new RuntimeException('A SINISA workbook does not contain its expected worksheet.');
            }
        } finally {
            $archive->close();
        }

        $reader = new XMLReader;
        $worksheetUrl = 'zip://'.str_replace('\\', '/', $xlsxFile).'#xl/worksheets/sheet1.xml';

        if (! $reader->open($worksheetUrl, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to stream a SINISA worksheet.');
        }

        try {
            $headers = null;
            $records = [];
            $rowCount = 0;

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowCount++;

                if ($rowCount > 10_000) {
                    throw new RuntimeException('A SINISA worksheet exceeds the safe row limit.');
                }

                $rowNumber = (int) $reader->getAttribute('r');

                if ($rowNumber < $headerRow) {
                    continue;
                }

                $values = $this->rowValues($reader->readOuterXml(), $sharedStrings);

                if ($rowNumber === $headerRow) {
                    $headers = $values;

                    continue;
                }

                if ($headers === null) {
                    throw new RuntimeException('The SINISA worksheet header was not found.');
                }

                $record = [];

                foreach ($headers as $column => $header) {
                    if ($header !== '') {
                        $record[$header] = $values[$column] ?? '';
                    }
                }

                $records[] = $record;
            }

            return $records;
        } finally {
            $reader->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $archive): array
    {
        $contents = $archive->getFromName('xl/sharedStrings.xml');

        if ($contents === false) {
            return [];
        }

        if (strlen($contents) > 10_000_000) {
            throw new RuntimeException('A SINISA shared string table exceeds the safe size limit.');
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to parse a SINISA shared string table.');
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
            throw new RuntimeException('Unable to parse a SINISA worksheet row.');
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
}
