<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\HomicideSourceDefinition;
use App\DTO\MunicipalData\SourceArtifact;

interface HomicideFetcher
{
    public function fetch(HomicideSourceDefinition $definition): SourceArtifact;
}
