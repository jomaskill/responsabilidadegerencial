<?php

namespace App\DTO\MunicipalData;

use InvalidArgumentException;

final readonly class ImportPeriod
{
    public function __construct(
        public int $fromYear,
        public int $toYear,
    ) {
        if ($fromYear > $toYear) {
            throw new InvalidArgumentException('The initial year must be less than or equal to the final year.');
        }
    }

    /** @return array<int, int> */
    public function years(): array
    {
        return range($this->fromYear, $this->toYear);
    }

    public function contains(int $year): bool
    {
        return $year >= $this->fromYear && $year <= $this->toYear;
    }
}
