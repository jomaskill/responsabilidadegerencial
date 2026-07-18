<?php

namespace App\MunicipalData;

interface IndicatorCalculator
{
    public function calculate(int $fromYear, int $toYear, ?string $indicatorSlug = null): ImportSummary;
}
