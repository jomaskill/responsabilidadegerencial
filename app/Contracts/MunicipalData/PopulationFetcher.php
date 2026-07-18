<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\PopulationSourceDefinition;
use App\DTO\MunicipalData\SourceArtifact;

interface PopulationFetcher
{
    public function fetch(PopulationSourceDefinition $definition): SourceArtifact;
}
