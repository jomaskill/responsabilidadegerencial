<?php

namespace App\MunicipalData;

interface ObservationTransformer
{
    /**
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    public function transform(array $record): array;
}
