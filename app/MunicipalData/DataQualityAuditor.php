<?php

namespace App\MunicipalData;

interface DataQualityAuditor
{
    /** @return array<int, array<string, int|float|string|null>> */
    public function coverage(int $referenceYear, ?string $sourceSlug = null): array;

    /** @return array{checked: int, warnings: int, rejected: int} */
    public function audit(int $referenceYear, ?string $sourceSlug = null): array;
}
