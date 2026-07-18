<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;

interface SanitationFetcher
{
    public function fetch(): SourceArtifact;
}
