<?php

namespace App\DTO\MunicipalData;

use DateTimeImmutable;

final readonly class HomicideSourceDefinition
{
    public function __construct(
        public int $year,
        public string $url,
        public string $file,
        public DateTimeImmutable $publishedAt,
        public int $expectedMunicipalities,
        public string $definition,
        public string $methodologyUrl,
    ) {}
}
