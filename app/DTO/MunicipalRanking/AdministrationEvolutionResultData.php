<?php

namespace App\DTO\MunicipalRanking;

final readonly class AdministrationEvolutionResultData
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rows,
        public array $meta,
    ) {}
}
