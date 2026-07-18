<?php

namespace App\Console\Commands;

use App\MunicipalData\DataQualityAuditor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('data:audit {--year= : Reference year} {--source= : Optional source slug}')]
#[Description('Audit ranges and quality flags in current municipal observations')]
class AuditMunicipalData extends Command
{
    public function handle(DataQualityAuditor $auditor): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $source = $this->option('source');
        $result = $auditor->audit($year, is_string($source) ? $source : null);

        $this->table(
            ['Observações verificadas', 'Alertas', 'Rejeitadas'],
            [[$result['checked'], $result['warnings'], $result['rejected']]],
        );

        return self::SUCCESS;
    }
}
