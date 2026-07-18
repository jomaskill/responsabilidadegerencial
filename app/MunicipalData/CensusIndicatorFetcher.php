<?php

namespace App\MunicipalData;

interface CensusIndicatorFetcher
{
    public function fetch(): SourceArtifact;
}
