<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;

interface IdebFetcher
{
    public function fetch(): SourceArtifact;
}
