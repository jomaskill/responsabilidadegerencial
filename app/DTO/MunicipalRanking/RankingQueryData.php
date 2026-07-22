<?php

namespace App\DTO\MunicipalRanking;

use InvalidArgumentException;

final readonly class RankingQueryData
{
    /**
     * @param  array<string, float>  $weights
     */
    public function __construct(
        public int $year,
        public ?string $theme = null,
        public ?string $federativeUnit = null,
        public ?int $populationMin = null,
        public ?int $populationMax = null,
        public array $weights = [],
        public int $page = 1,
        public int $perPage = 50,
    ) {
        $minimumYear = (int) config('municipal_ranking.minimum_year', 2017);
        $maximumYear = (int) config('municipal_ranking.maximum_year', 2025);

        if ($year < $minimumYear || $year > $maximumYear) {
            throw new InvalidArgumentException("Ranking year must be between {$minimumYear} and {$maximumYear}.");
        }

        if ($populationMin !== null && $populationMin < 0) {
            throw new InvalidArgumentException('Minimum population cannot be negative.');
        }

        if ($populationMax !== null && $populationMax < 0) {
            throw new InvalidArgumentException('Maximum population cannot be negative.');
        }

        if ($populationMin !== null && $populationMax !== null && $populationMin > $populationMax) {
            throw new InvalidArgumentException('Minimum population cannot exceed maximum population.');
        }

        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid ranking pagination.');
        }

        foreach ($weights as $slug => $weight) {
            if ($slug === '' || $weight < 0) {
                throw new InvalidArgumentException('Ranking weights must use valid slugs and non-negative values.');
            }
        }

        if ($weights !== [] && array_sum($weights) <= 0) {
            throw new InvalidArgumentException('At least one ranking weight must be greater than zero.');
        }
    }

    /** @return array<string, mixed> */
    public function calculationParameters(): array
    {
        $weights = $this->weights;
        ksort($weights);

        return [
            'year' => $this->year,
            'theme' => $this->theme,
            'federative_unit' => $this->federativeUnit,
            'population_min' => $this->populationMin,
            'population_max' => $this->populationMax,
            'weights' => $weights,
        ];
    }
}
