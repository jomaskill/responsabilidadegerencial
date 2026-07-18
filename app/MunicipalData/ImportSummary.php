<?php

namespace App\MunicipalData;

final readonly class ImportSummary
{
    public function __construct(
        public int $inputRows,
        public int $acceptedRows,
        public int $rejectedRows,
        public int $createdRows = 0,
    ) {}
}
