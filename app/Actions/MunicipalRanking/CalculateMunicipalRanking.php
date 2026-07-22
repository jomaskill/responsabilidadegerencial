<?php

namespace App\Actions\MunicipalRanking;

use App\DTO\MunicipalRanking\RankingQueryData;
use App\DTO\MunicipalRanking\RankingResultData;
use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use App\Models\Indicator;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\SourceRelease;
use App\Support\MunicipalRanking\MunicipalityScoreCalculator;
use App\Support\MunicipalRanking\NationalIndicatorCoverage;
use App\Support\MunicipalRanking\PercentileNormalizer;
use App\Support\MunicipalRanking\RankingMethodologyCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CalculateMunicipalRanking
{
    public function __construct(
        private readonly RankingMethodologyCatalog $catalog,
        private readonly PercentileNormalizer $normalizer,
        private readonly MunicipalityScoreCalculator $scoreCalculator,
        private readonly NationalIndicatorCoverage $nationalCoverage,
    ) {}

    public function execute(RankingQueryData $query): RankingResultData
    {
        $result = $this->all($query);
        $total = count($result['rows']);
        $offset = ($query->page - 1) * $query->perPage;
        $rows = array_slice($result['rows'], $offset, $query->perPage);

        return new RankingResultData($rows, $result['meta'] + [
            'pagination' => [
                'page' => $query->page,
                'per_page' => $query->perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $query->perPage)),
            ],
        ]);
    }

    /** @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function all(RankingQueryData $query): array
    {
        $revision = $this->dataRevision();
        $parameters = $query->calculationParameters();
        $cacheKey = 'municipal-ranking:'.hash('sha256', json_encode([
            'payload_version' => config('municipal_ranking.cache_payload_version', 1),
            'methodology' => config('municipal_ranking.methodology_version'),
            'revision' => $revision,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR));
        $fresh = (int) config('municipal_ranking.cache.fresh_seconds', 600);
        $stale = (int) config('municipal_ranking.cache.stale_seconds', 1800);

        /** @var array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} $result */
        $result = Cache::flexible($cacheKey, [$fresh, $stale], fn (): array => $this->calculate($query, $revision));

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function explanation(RankingQueryData $query, string $ibgeCode): ?array
    {
        $revision = $this->dataRevision();
        $cacheKey = 'municipal-ranking-explanation:'.hash('sha256', json_encode([
            'payload_version' => config('municipal_ranking.cache_payload_version', 1),
            'methodology' => config('municipal_ranking.methodology_version'),
            'revision' => $revision,
            'parameters' => $query->calculationParameters(),
            'ibge_code' => $ibgeCode,
        ], JSON_THROW_ON_ERROR));
        $fresh = (int) config('municipal_ranking.cache.fresh_seconds', 600);
        $stale = (int) config('municipal_ranking.cache.stale_seconds', 1800);

        /** @var array<string, mixed>|null $result */
        $result = Cache::flexible(
            $cacheKey,
            [$fresh, $stale],
            function () use ($query, $revision, $ibgeCode): ?array {
                foreach ($this->calculate($query, $revision, $ibgeCode)['rows'] as $row) {
                    if ($row['municipality']['ibge_code'] === $ibgeCode) {
                        return $row;
                    }
                }

                return null;
            },
        );

        return $result;
    }

    /** @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    private function calculate(
        RankingQueryData $query,
        string $revision,
        ?string $explanationIbgeCode = null,
    ): array {
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('slug')
            ->all();
        $effectiveYears = $this->nationalCoverage->completeEffectiveYears(
            $query->year,
            array_keys($indicators),
        );
        $weights = $this->catalog->resolveWeights(
            array_keys($effectiveYears),
            $indicators,
            $query->weights,
            $query->theme,
        );
        $effectiveYears = array_intersect_key($effectiveYears, $weights);
        $municipalities = $this->municipalities($query);
        $populationYear = $this->nationalCoverage->completeEffectiveYears(
            $query->year,
            ['population'],
        )['population'] ?? null;
        $populations = $populationYear === null ? [] : $this->populationValues($populationYear);
        $municipalities = $this->filterByPopulation($municipalities, $populations, $query);
        $explanationMunicipalityId = $municipalities
            ->filter(fn (array $municipality): bool => $municipality['ibge_code'] === $explanationIbgeCode)
            ->keys()
            ->first();
        $observations = $this->observations(
            $municipalities->keys()->all(),
            $effectiveYears,
            $indicators,
            is_int($explanationMunicipalityId) ? $explanationMunicipalityId : null,
        );
        $normalizedScores = [];

        foreach ($weights as $slug => $weight) {
            unset($weight);
            $values = [];

            foreach ($observations[$slug] ?? [] as $municipalityId => $observation) {
                $values[$municipalityId] = (float) $observation['value'];
            }

            $normalizedScores[$slug] = $this->normalizer->normalize(
                $values,
                $indicators[$slug]->rankingDirection(),
            );
        }

        $minimumCoverage = (float) config('municipal_ranking.minimum_coverage', 0.60);
        $rows = [];

        foreach ($municipalities as $municipalityId => $municipality) {
            $municipalityObservations = [];

            foreach (array_keys($weights) as $slug) {
                if (isset($observations[$slug][$municipalityId])) {
                    $municipalityObservations[$slug] = $observations[$slug][$municipalityId];
                }
            }

            $score = $this->scoreCalculator->calculate(
                $municipalityId,
                $weights,
                $municipalityObservations,
                $normalizedScores,
                $minimumCoverage,
                $municipalityId === $explanationMunicipalityId,
            );

            if ($municipalityId === $explanationMunicipalityId) {
                $score['components'] = array_map(
                    function (array $component) use ($indicators, $effectiveYears): array {
                        $indicator = $indicators[$component['indicator']];

                        return [
                            ...$component,
                            'name' => $indicator->name,
                            'theme' => $indicator->theme,
                            'unit' => $component['unit'] ?? $indicator->unit,
                            'direction' => $component['direction'] ?? $indicator->rankingDirection()->value,
                            'effective_year' => $component['effective_year'] ?? $effectiveYears[$indicator->slug],
                        ];
                    },
                    $score['components'],
                );
            } else {
                unset($score['components']);
            }

            $rows[] = [
                'rank' => null,
                'municipality' => [
                    'ibge_code' => $municipality['ibge_code'],
                    'name' => $municipality['name'],
                    'federative_unit' => $municipality['federative_unit'],
                ],
                'population' => $populations[$municipalityId] ?? null,
                'population_reference_year' => $populationYear,
                ...$score,
            ];
        }

        $rows = $this->sortRows($rows);

        $rank = 0;
        $previousScore = null;

        foreach ($rows as $index => &$row) {
            if ($row['score'] === null) {
                continue;
            }

            if ($previousScore === null || $row['score'] !== $previousScore) {
                $rank = $index + 1;
                $previousScore = $row['score'];
            }

            $row['rank'] = $rank;
        }
        unset($row);

        $ranked = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'ranked'));

        return [
            'rows' => $rows,
            'meta' => [
                'methodology_version' => (string) config('municipal_ranking.methodology_version'),
                'selected_year' => $query->year,
                'theme' => $query->theme,
                'federative_unit' => $query->federativeUnit,
                'population_min' => $query->populationMin,
                'population_max' => $query->populationMax,
                'minimum_coverage_percent' => $minimumCoverage * 100,
                'weights' => $weights,
                'effective_years' => $effectiveYears,
                'data_revision' => $revision,
                'generated_at' => now()->toIso8601String(),
                'ranked_municipalities' => $ranked,
                'insufficient_data_municipalities' => count($rows) - $ranked,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            if ($left['score'] === null && $right['score'] === null) {
                return [$left['municipality']['federative_unit'], $left['municipality']['name']]
                    <=> [$right['municipality']['federative_unit'], $right['municipality']['name']];
            }

            if ($left['score'] === null) {
                return 1;
            }

            if ($right['score'] === null) {
                return -1;
            }

            return $right['score'] <=> $left['score']
                ?: $left['municipality']['name'] <=> $right['municipality']['name'];
        });

        return $rows;
    }

    /** @return Collection<int, array{ibge_code: string, name: string, federative_unit: string}> */
    private function municipalities(RankingQueryData $query): Collection
    {
        return Municipality::query()
            ->join('federative_units', 'federative_units.id', '=', 'municipalities.federative_unit_id')
            ->existingInYear($query->year)
            ->when(
                $query->federativeUnit !== null,
                fn (Builder $builder) => $builder->where('federative_units.acronym', $query->federativeUnit),
            )
            ->orderBy('municipalities.id')
            ->get([
                'municipalities.id',
                'municipalities.ibge_code',
                'municipalities.name',
                'federative_units.acronym as federative_unit',
            ])
            ->mapWithKeys(fn (Municipality $municipality): array => [
                (int) $municipality->id => [
                    'ibge_code' => $municipality->ibge_code,
                    'name' => $municipality->name,
                    'federative_unit' => (string) $municipality->getAttribute('federative_unit'),
                ],
            ]);
    }

    /** @return array<int, float> */
    private function populationValues(int $referenceYear): array
    {
        return IndicatorObservation::query()
            ->join('indicator_versions', 'indicator_versions.id', '=', 'indicator_observations.indicator_version_id')
            ->join('indicators', 'indicators.id', '=', 'indicator_versions.indicator_id')
            ->join('source_releases', 'source_releases.id', '=', 'indicator_observations.source_release_id')
            ->where('indicators.slug', 'population')
            ->where('indicator_observations.reference_year', $referenceYear)
            ->whereNull('source_releases.superseded_by_id')
            ->where('indicator_observations.quality_status', QualityStatus::Accepted->value)
            ->whereIn('indicator_observations.availability_status', [
                AvailabilityStatus::Available->value,
                AvailabilityStatus::Provisional->value,
            ])
            ->pluck('indicator_observations.value', 'indicator_observations.municipality_id')
            ->map(fn ($value): float => (float) $value)
            ->all();
    }

    /**
     * @param  Collection<int, array{ibge_code: string, name: string, federative_unit: string}>  $municipalities
     * @param  array<int, float>  $populations
     * @return Collection<int, array{ibge_code: string, name: string, federative_unit: string}>
     */
    private function filterByPopulation(
        Collection $municipalities,
        array $populations,
        RankingQueryData $query,
    ): Collection {
        if ($query->populationMin === null && $query->populationMax === null) {
            return $municipalities;
        }

        return $municipalities->filter(function (array $municipality, int $municipalityId) use ($populations, $query): bool {
            unset($municipality);
            $population = $populations[$municipalityId] ?? null;

            if ($population === null) {
                return false;
            }

            return ($query->populationMin === null || $population >= $query->populationMin)
                && ($query->populationMax === null || $population <= $query->populationMax);
        });
    }

    /**
     * @param  array<int, int>  $municipalityIds
     * @param  array<string, int>  $effectiveYears
     * @param  array<string, Indicator>  $indicators
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function observations(
        array $municipalityIds,
        array $effectiveYears,
        array $indicators,
        ?int $explanationMunicipalityId = null,
    ): array {
        if ($municipalityIds === [] || $effectiveYears === []) {
            return [];
        }

        $records = DB::table('indicator_observations')
            ->join('indicator_versions', 'indicator_versions.id', '=', 'indicator_observations.indicator_version_id')
            ->join('indicators', 'indicators.id', '=', 'indicator_versions.indicator_id')
            ->join('source_releases', 'source_releases.id', '=', 'indicator_observations.source_release_id')
            ->join('data_sources', 'data_sources.id', '=', 'source_releases.data_source_id')
            ->whereIn('indicator_observations.municipality_id', $municipalityIds)
            ->whereNull('source_releases.superseded_by_id')
            ->where('indicator_observations.quality_status', QualityStatus::Accepted->value)
            ->whereIn('indicator_observations.availability_status', [
                AvailabilityStatus::Available->value,
                AvailabilityStatus::Provisional->value,
            ])
            ->where(function (QueryBuilder $builder) use ($effectiveYears): void {
                foreach ($effectiveYears as $slug => $year) {
                    $builder->orWhere(function (QueryBuilder $indicatorQuery) use ($slug, $year): void {
                        $indicatorQuery
                            ->where('indicators.slug', $slug)
                            ->where('indicator_observations.reference_year', $year);
                    });
                }
            })
            ->select([
                'indicator_observations.municipality_id',
                'indicator_observations.reference_year',
                'indicator_observations.value',
                'indicators.slug',
                'source_releases.id as release_id',
                'source_releases.version as release_version',
                'source_releases.published_at',
                'source_releases.source_url',
                'source_releases.checksum_sha256',
                'data_sources.slug as source_slug',
                'data_sources.name as source_name',
            ])
            ->orderBy('indicator_observations.id')
            ->lazy(1000);
        $observations = [];

        foreach ($records as $record) {
            $slug = (string) $record->slug;
            $municipalityId = (int) $record->municipality_id;
            $observation = [
                'value' => (float) $record->value,
                'reference_year' => (int) $record->reference_year,
            ];

            if ($municipalityId === $explanationMunicipalityId) {
                $indicator = $indicators[$slug];
                $observation += [
                    'unit' => $indicator->unit,
                    'direction' => $indicator->rankingDirection()->value,
                    'source' => [
                        'slug' => $record->source_slug,
                        'name' => $record->source_name,
                        'url' => $record->source_url,
                    ],
                    'release' => [
                        'id' => (int) $record->release_id,
                        'version' => $record->release_version,
                        'published_at' => $record->published_at,
                        'checksum_sha256' => $record->checksum_sha256,
                    ],
                ];
            }

            $observations[$slug][$municipalityId] = $observation;
        }

        return $observations;
    }

    private function dataRevision(): string
    {
        $releases = SourceRelease::query()
            ->whereNull('superseded_by_id')
            ->orderBy('id')
            ->get(['id', 'checksum_sha256', 'updated_at'])
            ->map(fn (SourceRelease $release): array => [
                $release->id,
                $release->checksum_sha256,
                $release->updated_at?->getTimestamp(),
            ])
            ->all();
        $catalogUpdatedAt = Indicator::query()->max('updated_at');

        return hash('sha256', json_encode([$releases, $catalogUpdatedAt], JSON_THROW_ON_ERROR));
    }
}
