<?php

namespace App\DTO\MunicipalData;

use App\Enums\ReleaseStatus;

final readonly class ImportObservationData
{
    public function __construct(
        public string $sourceSlug,
        public string $filePath,
        public ImportPeriod $period,
        public ReleaseStatus $releaseStatus,
        public string $releaseVersion = 'initial',
        public string $delimiter = ';',
        public ?string $sourceUrl = null,
    ) {}
}
