<?php

namespace App\MunicipalData;

interface SanitationFetcher
{
    public function fetch(): SourceArtifact;
}
