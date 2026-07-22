<?php

namespace App\Support\MunicipalRanking;

use App\Enums\IndicatorDirection;

class PercentileNormalizer
{
    /**
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    public function normalize(array $values, IndicatorDirection $direction): array
    {
        if ($values === []) {
            return [];
        }

        if ($direction === IndicatorDirection::ContextOnly) {
            return [];
        }

        $direction === IndicatorDirection::HigherIsBetter
            ? arsort($values, SORT_NUMERIC)
            : asort($values, SORT_NUMERIC);

        $count = count($values);

        if ($count === 1) {
            return [array_key_first($values) => 100.0];
        }

        $entries = [];

        foreach ($values as $municipalityId => $value) {
            $entries[] = ['municipality_id' => $municipalityId, 'value' => $value];
        }

        $scores = [];
        $index = 0;

        while ($index < $count) {
            $groupEnd = $index;

            while ($groupEnd + 1 < $count && $entries[$groupEnd + 1]['value'] === $entries[$index]['value']) {
                $groupEnd++;
            }

            $averageRank = (($index + 1) + ($groupEnd + 1)) / 2;
            $score = round(100 * (($count - $averageRank) / ($count - 1)), 8);

            for ($position = $index; $position <= $groupEnd; $position++) {
                $scores[$entries[$position]['municipality_id']] = $score;
            }

            $index = $groupEnd + 1;
        }

        return $scores;
    }
}
