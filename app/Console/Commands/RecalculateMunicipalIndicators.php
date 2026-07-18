<?php

namespace App\Console\Commands;

use App\MunicipalData\IndicatorCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('data:recalculate {indicator? : Calculated indicator slug} {--from=2017 : First reference year} {--to=2025 : Last reference year}')]
#[Description('Recalculate derived municipal indicators from immutable inputs')]
class RecalculateMunicipalIndicators extends Command
{
    public function handle(IndicatorCalculator $calculator): int
    {
        $indicator = $this->argument('indicator');
        $summary = $calculator->calculate(
            (int) $this->option('from'),
            (int) $this->option('to'),
            is_string($indicator) ? $indicator : null,
        );

        $this->table(
            ['Entradas usadas', 'Resultados', 'Novos resultados'],
            [[$summary->inputRows, $summary->acceptedRows, $summary->createdRows]],
        );

        return self::SUCCESS;
    }
}
