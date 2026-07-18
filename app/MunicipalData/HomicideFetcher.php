<?php

namespace App\MunicipalData;

interface HomicideFetcher
{
    public function fetch(HomicideSourceDefinition $definition): SourceArtifact;
}
