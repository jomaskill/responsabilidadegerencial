<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;

interface CensusIndicatorFetcher
{
    public function fetch(): SourceArtifact;
}
