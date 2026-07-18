<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;

interface SourceFetcher
{
    public function fetch(int $referenceYear): SourceArtifact;
}
