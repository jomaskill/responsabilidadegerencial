<?php

namespace App\DTO\MunicipalRanking;

use InvalidArgumentException;

final readonly class AdministrationEvolutionQueryData
{
    public function __construct(
        public int $electionYear,
        public ?string $federativeUnit = null,
        public ?int $populationMin = null,
        public ?int $populationMax = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {
        if (! in_array($electionYear, [2016, 2020, 2024], true)) {
            throw new InvalidArgumentException('Election year must be 2016, 2020 or 2024.');
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
            throw new InvalidArgumentException('Invalid administration ranking pagination.');
        }
    }

    /** @return array<string, int|string|null> */
    public function calculationParameters(): array
    {
        return [
            'election_year' => $this->electionYear,
            'federative_unit' => $this->federativeUnit,
            'population_min' => $this->populationMin,
            'population_max' => $this->populationMax,
        ];
    }
}
