<?php

namespace App\Support\MunicipalData\Parsers;

use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class TseElectionArchiveParser
{
    /** @return array<int, array{tse_code: string, ibge_code: string}> */
    public function municipalityCodes(string $contents): array
    {
        $records = [];

        foreach ($this->records($contents, 'municipio_tse_ibge') as $record) {
            $tseCode = $this->field($record, ['CD_MUNICIPIO_TSE', 'CODIGO_TSE', 'CD_MUNICIPIO']);
            $ibgeCode = $this->field($record, ['CD_MUNICIPIO_IBGE', 'CODIGO_IBGE']);

            if ($tseCode === '' || ! preg_match('/^\d{7}$/', $ibgeCode)) {
                continue;
            }

            $records[] = ['tse_code' => ltrim($tseCode, '0') ?: '0', 'ibge_code' => $ibgeCode];
        }

        if ($records === []) {
            throw new RuntimeException('The TSE–IBGE correspondence did not contain valid municipalities.');
        }

        return $records;
    }

    /**
     * @return array<int, array{
     *   tse_code: string,
     *   external_identifier: string,
     *   name: string,
     *   party_acronym: string|null
     * }>
     */
    public function electedMayors(string $contents, int $electionYear): array
    {
        $mayors = [];

        foreach ($this->records($contents, "consulta_cand_{$electionYear}") as $record) {
            if ((int) $this->field($record, ['ANO_ELEICAO']) !== $electionYear) {
                continue;
            }

            $electionType = Str::ascii(Str::upper($this->field($record, ['NM_TIPO_ELEICAO'])));

            if ($electionType !== '' && $electionType !== 'ELEICAO ORDINARIA') {
                continue;
            }

            $office = Str::ascii(Str::upper($this->field($record, ['DS_CARGO'])));
            $result = Str::ascii(Str::upper($this->field($record, ['DS_SIT_TOT_TURNO'])));

            if ($office !== 'PREFEITO' || $result !== 'ELEITO') {
                continue;
            }

            $tseCode = ltrim($this->field($record, ['CD_MUNICIPIO', 'SG_UE']), '0') ?: '0';
            $externalIdentifier = $this->field($record, ['SQ_CANDIDATO']);
            $name = $this->field($record, ['NM_URNA_CANDIDATO', 'NM_CANDIDATO']);

            if ($tseCode === '0' || $externalIdentifier === '' || $name === '') {
                continue;
            }

            $mayors[] = [
                'tse_code' => $tseCode,
                'external_identifier' => $externalIdentifier,
                'name' => $name,
                'party_acronym' => $this->nullable($this->field($record, ['SG_PARTIDO'])),
            ];
        }

        if ($mayors === []) {
            throw new RuntimeException("The TSE candidate package did not contain elected mayors for {$electionYear}.");
        }

        return $mayors;
    }

    /** @return iterable<int, array<string, string>> */
    private function records(string $contents, string $entryFragment): iterable
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'tse-source-');

        if ($temporaryFile === false || file_put_contents($temporaryFile, $contents) === false) {
            throw new RuntimeException('Unable to prepare the TSE source archive.');
        }

        $archive = new ZipArchive;

        try {
            if ($archive->open($temporaryFile) !== true) {
                throw new RuntimeException('Unable to open the TSE source archive.');
            }

            $entryNames = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $candidate = (string) $archive->getNameIndex($index);

                if (Str::contains(Str::lower($candidate), Str::lower($entryFragment)) && Str::endsWith(Str::lower($candidate), '.csv')) {
                    $entryNames[] = $candidate;
                }
            }

            if ($entryNames === []) {
                throw new RuntimeException("The expected TSE CSV entry was not found: {$entryFragment}");
            }

            usort($entryNames, function (string $left, string $right): int {
                $leftIsNational = Str::contains(Str::upper($left), '_BRASIL.CSV');
                $rightIsNational = Str::contains(Str::upper($right), '_BRASIL.CSV');

                return $rightIsNational <=> $leftIsNational ?: $left <=> $right;
            });
            $entryName = $entryNames[0];
            $stream = $archive->getStream($entryName);

            if ($stream === false) {
                throw new RuntimeException("Unable to read the TSE CSV entry: {$entryName}");
            }

            $headers = null;

            while (($row = fgetcsv($stream, null, ';', '"', '\\')) !== false) {
                $row = array_map(fn (mixed $value): string => $this->utf8((string) $value), $row);

                if ($headers === null) {
                    $headers = array_map(fn (string $header): string => ltrim(trim($header), "\xEF\xBB\xBF"), $row);

                    continue;
                }

                $row = array_pad($row, count($headers), '');
                $record = array_combine($headers, array_slice($row, 0, count($headers)));

                yield array_map('trim', $record);
            }

            fclose($stream);
        } finally {
            if ($archive->status === ZipArchive::ER_OK) {
                $archive->close();
            }

            @unlink($temporaryFile);
        }
    }

    /**
     * @param  array<string, string>  $record
     * @param  array<int, string>  $names
     */
    private function field(array $record, array $names): string
    {
        foreach ($names as $name) {
            if (isset($record[$name]) && $record[$name] !== '') {
                return trim($record[$name]);
            }
        }

        return '';
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
