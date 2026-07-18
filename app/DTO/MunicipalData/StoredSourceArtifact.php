<?php

namespace App\DTO\MunicipalData;

final readonly class StoredSourceArtifact
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $checksum,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
