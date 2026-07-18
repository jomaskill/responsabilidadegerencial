<?php

namespace App\MunicipalData;

use DateTimeImmutable;

final readonly class PopulationSourceDefinition
{
    public function __construct(
        public int $year,
        public string $url,
        public string $format,
        public string $methodology,
        public string $statisticalReferenceDate,
        public string $territorialReference,
        public DateTimeImmutable $publishedAt,
        public int $expectedRecords,
        public int $expectedAvailableMunicipalities,
        public string $dataset,
    ) {}

    /** @param array<string, int|string> $configuration */
    public static function fromConfiguration(int $year, array $configuration): self
    {
        return new self(
            year: $year,
            url: (string) $configuration['url'],
            format: (string) $configuration['format'],
            methodology: (string) $configuration['methodology'],
            statisticalReferenceDate: (string) $configuration['statistical_reference_date'],
            territorialReference: (string) $configuration['territorial_reference'],
            publishedAt: new DateTimeImmutable((string) $configuration['published_at']),
            expectedRecords: (int) $configuration['expected_records'],
            expectedAvailableMunicipalities: (int) $configuration['expected_available_municipalities'],
            dataset: (string) $configuration['dataset'],
        );
    }
}
