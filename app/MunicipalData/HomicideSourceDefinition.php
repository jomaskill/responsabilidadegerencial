<?php

namespace App\MunicipalData;

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

    /** @param array<string, int|string> $configuration */
    public static function fromConfiguration(int $year, array $configuration): self
    {
        return new self(
            year: $year,
            url: (string) config('municipal_data.homicides.url'),
            file: (string) $configuration['file'],
            publishedAt: new DateTimeImmutable((string) $configuration['published_at']),
            expectedMunicipalities: (int) $configuration['expected_municipalities'],
            definition: (string) config('municipal_data.homicides.definition'),
            methodologyUrl: (string) config('municipal_data.homicides.methodology_url'),
        );
    }
}
