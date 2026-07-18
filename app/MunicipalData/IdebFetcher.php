<?php

namespace App\MunicipalData;

interface IdebFetcher
{
    public function fetch(): SourceArtifact;
}
