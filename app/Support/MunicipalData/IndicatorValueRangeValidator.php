<?php

namespace App\Support\MunicipalData;

final class IndicatorValueRangeValidator
{
    public function violation(?string $value, string $unit): ?string
    {
        if ($value === null) {
            return null;
        }

        $numericValue = (float) $value;

        if ($unit === 'percentual' && ($numericValue < 0 || $numericValue > 100)) {
            return 'Percentage is outside the expected 0–100 range.';
        }

        if ($unit === 'indice_0_10' && ($numericValue < 0 || $numericValue > 10)) {
            return 'Index is outside the expected 0–10 range.';
        }

        if (in_array($unit, ['pessoas', 'obitos', 'BRL', 'BRL_por_habitante'], true) && $numericValue < 0) {
            return 'Value cannot be negative for this indicator.';
        }

        return null;
    }
}
