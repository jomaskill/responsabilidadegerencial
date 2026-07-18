<?php

namespace App\MunicipalData\Parsers;

use App\MunicipalData\SourceParser;
use RuntimeException;
use SplFileObject;

class DelimitedSourceParser implements SourceParser
{
    public function records(string $path, array $options = []): iterable
    {
        if (! is_readable($path)) {
            throw new RuntimeException("The source file is not readable: {$path}");
        }

        $delimiter = (string) ($options['delimiter'] ?? ';');
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl($delimiter, '"', '\\');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);

        $headers = null;

        foreach ($file as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(function (mixed $header): string {
                    $normalized = trim((string) $header);

                    return ltrim($normalized, "\xEF\xBB\xBF");
                }, $row);

                continue;
            }

            $row = array_pad($row, count($headers), null);
            $record = array_combine($headers, array_slice($row, 0, count($headers)));

            yield $file->key() + 1 => $record;
        }
    }
}
