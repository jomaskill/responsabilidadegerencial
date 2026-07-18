<?php

namespace App\Support\MunicipalData\Parsers;

use App\DTO\MunicipalData\PopulationSourceDefinition;
use App\Enums\AvailabilityStatus;
use JsonException;
use RuntimeException;

final class PopulationSourceParser
{
    public function __construct(
        private readonly OdsSourceParser $odsParser,
    ) {}

    /**
     * @return iterable<int, array{municipality_code: string, raw_value: string}>
     *
     * @throws JsonException
     */
    public function records(string $contents, PopulationSourceDefinition $definition): iterable
    {
        if ($definition->format === 'json') {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            foreach ($decoded as $index => $row) {
                $code = $row['D1C'] ?? null;

                if (! is_string($code) || preg_match('/^\d{7}$/', $code) !== 1) {
                    continue;
                }

                yield $index + 1 => [
                    'municipality_code' => $code,
                    'raw_value' => (string) ($row['V'] ?? ''),
                ];
            }

            return;
        }

        foreach ($this->odsParser->rows($contents) as $rowNumber => $row) {
            $ufCode = $row[1] ?? '';
            $municipalityCode = $row[2] ?? '';

            if (preg_match('/^\d{2}$/', $ufCode) !== 1 || preg_match('/^\d{5}$/', $municipalityCode) !== 1) {
                continue;
            }

            yield $rowNumber => [
                'municipality_code' => $ufCode.$municipalityCode,
                'raw_value' => $row[4] ?? '',
            ];
        }
    }

    public function availabilityStatus(string $rawValue, ?string $installedAt, int $referenceYear): AvailabilityStatus
    {
        $digits = $this->digits($rawValue);

        if ($digits !== '') {
            return AvailabilityStatus::Available;
        }

        if ($installedAt !== null && (int) substr($installedAt, 0, 4) > $referenceYear) {
            return AvailabilityStatus::NotApplicable;
        }

        return match (trim($rawValue)) {
            'X', 'x' => AvailabilityStatus::Suppressed,
            default => AvailabilityStatus::MissingFromSource,
        };
    }

    public function value(string $rawValue): int
    {
        $digits = $this->digits($rawValue);

        if ($digits === '') {
            throw new RuntimeException("Invalid population value: {$rawValue}");
        }

        return (int) $digits;
    }

    private function digits(string $rawValue): string
    {
        $withoutFootnotes = preg_replace('/\([^)]*\)|\[[^]]*\]/u', '', $rawValue);

        return preg_replace('/\D/u', '', $withoutFootnotes ?? '') ?? '';
    }
}
