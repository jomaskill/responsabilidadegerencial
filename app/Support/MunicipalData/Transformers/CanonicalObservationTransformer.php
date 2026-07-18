<?php

namespace App\Support\MunicipalData\Transformers;

use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use InvalidArgumentException;

class CanonicalObservationTransformer
{
    /**
     * @param  array<string, string|null>  $record
     * @return array{
     *     municipality_code: string,
     *     indicator_slug: string,
     *     indicator_version: int,
     *     reference_year: int,
     *     period_start: string|null,
     *     period_end: string|null,
     *     value: string|null,
     *     numerator: string|null,
     *     denominator: string|null,
     *     availability_status: AvailabilityStatus,
     *     quality_status: QualityStatus,
     *     notes: string|null
     * }
     */
    public function transform(array $record): array
    {
        foreach (['municipality_code', 'indicator_slug', 'reference_year'] as $required) {
            if (! isset($record[$required]) || trim((string) $record[$required]) === '') {
                throw new InvalidArgumentException("Required column is empty: {$required}");
            }
        }

        $availability = AvailabilityStatus::tryFrom(($record['availability_status'] ?? null) ?: AvailabilityStatus::Available->value);
        $quality = QualityStatus::tryFrom(($record['quality_status'] ?? null) ?: QualityStatus::Accepted->value);

        if ($availability === null) {
            throw new InvalidArgumentException('Invalid availability_status.');
        }

        if ($quality === null) {
            throw new InvalidArgumentException('Invalid quality_status.');
        }

        $value = $this->decimal($record['value'] ?? null);

        if (in_array($availability, [AvailabilityStatus::Available, AvailabilityStatus::Provisional], true) && $value === null) {
            throw new InvalidArgumentException('Available observations require a value.');
        }

        return [
            'municipality_code' => str_pad(trim((string) $record['municipality_code']), 7, '0', STR_PAD_LEFT),
            'indicator_slug' => trim((string) $record['indicator_slug']),
            'indicator_version' => max(1, (int) (($record['indicator_version'] ?? null) ?: 1)),
            'reference_year' => (int) $record['reference_year'],
            'period_start' => ($record['period_start'] ?? null) ?: null,
            'period_end' => ($record['period_end'] ?? null) ?: null,
            'value' => $value,
            'numerator' => $this->decimal($record['numerator'] ?? null),
            'denominator' => $this->decimal($record['denominator'] ?? null),
            'availability_status' => $availability,
            'quality_status' => $quality,
            'notes' => ($record['notes'] ?? null) ?: null,
        ];
    }

    private function decimal(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
        }

        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("Invalid decimal value: {$value}");
        }

        return $normalized;
    }
}
