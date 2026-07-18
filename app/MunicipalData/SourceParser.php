<?php

namespace App\MunicipalData;

interface SourceParser
{
    /**
     * @param  array<string, mixed>  $options
     * @return iterable<int, array<string, string|null>>
     */
    public function records(string $path, array $options = []): iterable;
}
