<?php

namespace App\Support\MunicipalData\Parsers;

use RuntimeException;

final class DatasusHomicideParser
{
    /** @return array{counts: array<int, int>, national_total: int, source_rows: int} */
    public function parse(string $contents): array
    {
        if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $contents, $match) !== 1) {
            throw new RuntimeException('The DATASUS response does not contain the expected table.');
        }

        $lines = preg_split('/\R/', html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'ISO-8859-1'));
        $counts = [];
        $reportedTotal = null;
        $summedTotal = 0;
        $sourceRows = 0;

        foreach ($lines ?: [] as $line) {
            $fields = str_getcsv(trim($line), ';', '"', '');

            if (count($fields) < 2) {
                continue;
            }

            $label = trim($fields[0]);
            $digits = preg_replace('/\D/', '', $fields[1]);

            if ($digits === null || $digits === '') {
                continue;
            }

            $value = (int) $digits;

            if (strcasecmp($label, 'Total') === 0) {
                $reportedTotal = $value;

                continue;
            }

            $sourceRows++;
            $summedTotal += $value;

            if (preg_match('/^\s*(\d{6})\s+/', $label, $codeMatch) === 1) {
                $counts[(int) $codeMatch[1]] = $value;
            }
        }

        if ($reportedTotal === null || $reportedTotal !== $summedTotal) {
            throw new RuntimeException("DATASUS total validation failed: reported {$reportedTotal}, summed {$summedTotal}.");
        }

        return [
            'counts' => $counts,
            'national_total' => $reportedTotal,
            'source_rows' => $sourceRows,
        ];
    }
}
