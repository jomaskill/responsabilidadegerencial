<?php

namespace App\Console\Commands;

use App\Actions\MunicipalData\ReportMunicipalDataCoverage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('data:coverage {--year= : Reference year} {--source= : Optional source slug}')]
#[Description('Report municipal coverage for every active indicator')]
class ReportMunicipalCoverage extends Command
{
    public function handle(ReportMunicipalDataCoverage $action): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $source = $this->option('source');
        $rows = $action->execute($year, is_string($source) ? $source : null);

        $this->table(
            ['Indicador', 'Tema', 'Municípios esperados', 'Com dado', 'Sem dado', 'Cobertura %'],
            array_map(fn (array $row) => [
                $row['indicator'],
                $row['theme'],
                $row['expected'],
                $row['available'],
                $row['missing'],
                $row['coverage_percent'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
