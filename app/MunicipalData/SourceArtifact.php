<?php

namespace App\MunicipalData;

use DateTimeImmutable;

final readonly class SourceArtifact
{
    public function __construct(
        public string $contents,
        public string $sourceUrl,
        public string $extension,
        public string $mimeType,
        public ?DateTimeImmutable $publishedAt = null,
    ) {}
}
