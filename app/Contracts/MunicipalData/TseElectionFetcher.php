<?php

namespace App\Contracts\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;

interface TseElectionFetcher
{
    public function candidates(int $electionYear): SourceArtifact;

    public function municipalityCodes(): SourceArtifact;
}
