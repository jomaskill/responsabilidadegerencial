<?php

namespace App\MunicipalData\Parsers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Generator;
use RuntimeException;
use ZipArchive;

class OdsSourceParser
{
    private const TABLE_NAMESPACE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    private const MAX_SOURCE_COLUMNS = 1_048_576;

    private const REQUIRED_COLUMNS = 5;

    /** @return Generator<int, array<int, string>> */
    public function rows(string $contents): Generator
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'population-ods-');

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to create the temporary ODS file.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryFile) !== true) {
                throw new RuntimeException('The downloaded population artifact is not a valid ODS file.');
            }

            try {
                $contentXml = $archive->getFromName('content.xml');
            } finally {
                $archive->close();
            }

            if ($contentXml === false || mb_strlen($contentXml, '8bit') > 50_000_000) {
                throw new RuntimeException('The ODS content.xml file is missing or exceeds the safe size limit.');
            }

            yield from $this->rowsFromContentXml($contentXml);
        } finally {
            @unlink($temporaryFile);
        }
    }

    /** @return Generator<int, array<int, string>> */
    public function rowsFromContentXml(string $contentXml): Generator
    {
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        if (! $document->loadXML($contentXml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to parse the ODS content.xml file.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('table', self::TABLE_NAMESPACE);
        $tables = $xpath->query('//table:table');

        if ($tables === false || $tables->length === 0) {
            throw new RuntimeException('The ODS file does not contain a spreadsheet table.');
        }

        $table = $tables->item(0);

        if (! $table instanceof DOMElement) {
            throw new RuntimeException('The first ODS spreadsheet table is invalid.');
        }

        $rowNodes = $xpath->query('./table:table-row', $table);

        if ($rowNodes === false) {
            throw new RuntimeException('Unable to read rows from the ODS table.');
        }

        $rowNumber = 0;

        foreach ($rowNodes as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $cells = [];
            $cellNodes = $xpath->query('./table:table-cell | ./table:covered-table-cell', $rowNode);

            if ($cellNodes === false) {
                continue;
            }

            foreach ($cellNodes as $cellNode) {
                if (! $cellNode instanceof DOMElement) {
                    continue;
                }

                $value = trim($cellNode->textContent);
                $repetitions = max(1, (int) $cellNode->getAttributeNS(self::TABLE_NAMESPACE, 'number-columns-repeated'));

                if ($repetitions > self::MAX_SOURCE_COLUMNS) {
                    throw new RuntimeException('The ODS file contains an unsafe repeated-column count.');
                }

                $requiredRepetitions = min($repetitions, max(0, self::REQUIRED_COLUMNS - count($cells)));

                for ($index = 0; $index < $requiredRepetitions; $index++) {
                    $cells[] = $value;
                }

                if (count($cells) === self::REQUIRED_COLUMNS) {
                    break;
                }
            }

            if (collect($cells)->every(fn (string $value): bool => $value === '')) {
                continue;
            }

            $rowRepetitions = max(1, (int) $rowNode->getAttributeNS(self::TABLE_NAMESPACE, 'number-rows-repeated'));

            if ($rowRepetitions > 10_000) {
                throw new RuntimeException('The ODS file contains an unsafe repeated-row count.');
            }

            for ($index = 0; $index < $rowRepetitions; $index++) {
                yield ++$rowNumber => $cells;
            }
        }
    }
}
