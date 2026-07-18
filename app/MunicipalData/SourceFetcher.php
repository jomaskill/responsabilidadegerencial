<?php

namespace App\MunicipalData;

interface SourceFetcher
{
    public function fetch(int $referenceYear): SourceArtifact;
}
