<?php

namespace App\MunicipalData;

interface PopulationFetcher
{
    public function fetch(PopulationSourceDefinition $definition): SourceArtifact;
}
