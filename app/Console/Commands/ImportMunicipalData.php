<?php

namespace App\Console\Commands;

use App\Actions\MunicipalData\ImportDatasusHomicides;
use App\Actions\MunicipalData\ImportIbgeCensusIndicators;
use App\Actions\MunicipalData\ImportIbgeGdp;
use App\Actions\MunicipalData\ImportIbgeMunicipalities;
use App\Actions\MunicipalData\ImportIbgePopulation;
use App\Actions\MunicipalData\ImportInepIdeb;
use App\Actions\MunicipalData\ImportObservationCsv;
use App\Actions\MunicipalData\ImportSinisaSanitation;
use App\Enums\ReleaseStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('data:import {source : Source slug} {--from= : First reference year} {--to= : Last reference year} {--file= : Local canonical CSV file} {--status=final : provisional, final or revised} {--release-version=initial : Source release version} {--delimiter=; : CSV delimiter} {--source-url= : Official source URL}')]
#[Description('Import the municipality registry or canonical municipal observations')]
class ImportMunicipalData extends Command
{
    public function handle(
        ImportObservationCsv $csvImporter,
        ImportIbgeMunicipalities $municipalityImporter,
        ImportIbgePopulation $populationImporter,
        ImportDatasusHomicides $homicideImporter,
        ImportIbgeGdp $gdpImporter,
        ImportIbgeCensusIndicators $censusIndicatorImporter,
        ImportInepIdeb $idebImporter,
        ImportSinisaSanitation $sanitationImporter,
    ): int {
        try {
            $source = (string) $this->argument('source');
            $from = (int) ($this->option('from') ?: now()->year);
            $to = (int) ($this->option('to') ?: $from);
            $file = $this->option('file');

            if ($source === 'ibge-localidades' && ! is_string($file)) {
                $summary = $municipalityImporter->execute($to);
            } elseif ($source === 'ibge-populacao' && ! is_string($file)) {
                $summary = $populationImporter->execute($from, $to);
            } elseif ($source === 'datasus-sim' && ! is_string($file)) {
                $summary = $homicideImporter->execute($from, $to);

                if ($to > (int) config('municipal_data.homicides.available_through')) {
                    $this->warn((string) config('municipal_data.homicides.unavailable_note'));
                }
            } elseif ($source === 'ibge-pib-municipios' && ! is_string($file)) {
                $summary = $gdpImporter->execute($from, $to);

                if ($to > (int) config('municipal_data.gdp.available_through')) {
                    $this->warn((string) config('municipal_data.gdp.unavailable_note'));
                }
            } elseif ($source === 'ibge-censo-2022' && ! is_string($file)) {
                $summary = $censusIndicatorImporter->execute();
            } elseif ($source === 'inep-ideb' && ! is_string($file)) {
                $summary = $idebImporter->execute($from, $to);

                if ($to > (int) config('municipal_data.ideb.available_through')) {
                    $this->warn((string) config('municipal_data.ideb.unavailable_note'));
                }
            } elseif ($source === 'sinisa' && ! is_string($file)) {
                $summary = $sanitationImporter->execute($from, $to);

                if ($to > (int) config('municipal_data.sinisa.available_through')) {
                    $this->warn((string) config('municipal_data.sinisa.unavailable_note'));
                }
            } else {
                if (! is_string($file) || $file === '') {
                    $this->error('Use --file for sources without a configured official collector.');

                    return self::FAILURE;
                }

                $status = ReleaseStatus::tryFrom((string) $this->option('status'));

                if ($status === null) {
                    $this->error('Invalid --status. Use provisional, final or revised.');

                    return self::FAILURE;
                }

                $summary = $csvImporter->execute(
                    sourceSlug: $source,
                    filePath: $file,
                    fromYear: $from,
                    toYear: $to,
                    releaseStatus: $status,
                    releaseVersion: (string) $this->option('release-version'),
                    delimiter: (string) $this->option('delimiter'),
                    sourceUrl: is_string($this->option('source-url')) ? $this->option('source-url') : null,
                );
            }

            $this->table(
                ['Linhas lidas', 'Aceitas', 'Rejeitadas', 'Novas observações'],
                [[$summary->inputRows, $summary->acceptedRows, $summary->rejectedRows, $summary->createdRows]],
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
