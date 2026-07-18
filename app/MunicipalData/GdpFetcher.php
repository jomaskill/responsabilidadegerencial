<?php

namespace App\MunicipalData;

interface GdpFetcher
{
    public function fetch(): SourceArtifact;
}
