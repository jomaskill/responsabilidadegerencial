<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;

interface GdpFetcher
{
    public function fetch(): SourceArtifact;
}
