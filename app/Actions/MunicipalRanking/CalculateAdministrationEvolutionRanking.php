<?php

namespace App\Actions\MunicipalRanking;

use App\DTO\MunicipalRanking\AdministrationEvolutionQueryData;
use App\DTO\MunicipalRanking\AdministrationEvolutionResultData;
use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use App\Models\Administration;
use App\Models\AdministrationOfficeHolder;
use App\Models\Indicator;
use App\Models\IndicatorObservation;
use App\Models\SourceRelease;
use App\Support\MunicipalRanking\AdministrationEvolutionMethodology;
use App\Support\MunicipalRanking\AdministrationEvolutionScoreCalculator;
use App\Support\MunicipalRanking\NationalIndicatorCoverage;
use App\Support\MunicipalRanking\PercentileNormalizer;
use App\Support\MunicipalRanking\RankingMethodologyCatalog;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CalculateAdministrationEvolutionRanking
{
    public function __construct(
        private readonly RankingMethodologyCatalog $catalog,
        private readonly PercentileNormalizer $normalizer,
        private readonly AdministrationEvolutionMethodology $methodology,
        private readonly AdministrationEvolutionScoreCalculator $scoreCalculator,
        private readonly NationalIndicatorCoverage $nationalCoverage,
    ) {}

    public function execute(AdministrationEvolutionQueryData $query): AdministrationEvolutionResultData
    {
        $result = $this->all($query);
        $total = count($result['rows']);
        $offset = ($query->page - 1) * $query->perPage;

        return new AdministrationEvolutionResultData(
            rows: array_slice($result['rows'], $offset, $query->perPage),
            meta: $result['meta'] + [
                'pagination' => [
                    'page' => $query->page,
                    'per_page' => $query->perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $query->perPage)),
                ],
            ],
        );
    }

    /** @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function all(AdministrationEvolutionQueryData $query): array
    {
        $revision = $this->dataRevision();
        $cacheKey = 'administration-evolution-ranking:'.hash('sha256', json_encode([
            'payload_version' => config('municipal_ranking.cache_payload_version', 1),
            'methodology' => config('municipal_ranking.methodology_version'),
            'revision' => $revision,
            'parameters' => $query->calculationParameters(),
        ], JSON_THROW_ON_ERROR));

        /** @var array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} $result */
        $result = Cache::flexible(
            $cacheKey,
            [
                (int) config('municipal_ranking.cache.fresh_seconds', 600),
                (int) config('municipal_ranking.cache.stale_seconds', 1800),
            ],
            fn (): array => $this->calculate($query, $revision),
        );

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function explanation(AdministrationEvolutionQueryData $query, int $administrationId): ?array
    {
        $revision = $this->dataRevision();
        $cacheKey = 'administration-evolution-explanation:'.hash('sha256', json_encode([
            'payload_version' => config('municipal_ranking.cache_payload_version', 1),
            'methodology' => config('municipal_ranking.methodology_version'),
            'revision' => $revision,
            'parameters' => $query->calculationParameters(),
            'administration_id' => $administrationId,
        ], JSON_THROW_ON_ERROR));

        /** @var array<string, mixed>|null $result */
        $result = Cache::flexible(
            $cacheKey,
            [
                (int) config('municipal_ranking.cache.fresh_seconds', 600),
                (int) config('municipal_ranking.cache.stale_seconds', 1800),
            ],
            function () use ($query, $revision, $administrationId): ?array {
                foreach ($this->calculate($query, $revision, $administrationId)['rows'] as $row) {
                    if ($row['administration']['id'] === $administrationId) {
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
        AdministrationEvolutionQueryData $query,
        string $revision,
        ?int $explanationAdministrationId = null,
    ): array {
        $endpoints = $this->methodology->endpoints($query->electionYear);
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('slug')
            ->all();
        $eligibleSlugs = array_values(array_filter(
            array_keys($indicators),
            fn (string $slug): bool => $this->catalog->isEligible($indicators[$slug]),
        ));
        $baselineYears = $this->nationalCoverage->completeEffectiveYears(
            $endpoints['baseline_year'],
            $eligibleSlugs,
        );
        $endYears = $this->nationalCoverage->completeEffectiveYears(
            $endpoints['end_year'],
            $eligibleSlugs,
        );
        $advancedSlugs = $this->methodology->advancedIndicators($baselineYears, $endYears);
        $globalCoverage = $this->methodology->globalCoverageForConfiguredProfile($advancedSlugs);
        $minimumCoverage = (float) config('municipal_ranking.minimum_coverage', 0.60);
        $weights = $this->weightsOrEmpty($advancedSlugs, $indicators);
        $canPublish = $weights !== [];
        $populationYear = $this->nationalCoverage->completeEffectiveYears(
            $endpoints['end_year'],
            ['population'],
        )['population'] ?? null;
        $populations = $populationYear === null ? [] : $this->populationValues($populationYear);
        $administrations = collect($this->administrations($query, $endpoints['end_year'], $populations));

        if (! $canPublish) {
            $rows = $administrations
                ->map(fn (array $administration): array => $this->baseRow(
                    $administration,
                    $populations[$administration['municipality_id']] ?? null,
                    $populationYear,
                ) + [
                    'evolution_score' => null,
                    'coverage_percent' => 0.0,
                    'status' => 'awaiting_new_data',
                    'evolution_summary' => null,
                ])
                ->values()
                ->all();

            return [
                'rows' => $rows,
                'meta' => $this->meta(
                    $query,
                    $endpoints,
                    $baselineYears,
                    $endYears,
                    $advancedSlugs,
                    $weights,
                    $globalCoverage,
                    $revision,
                    0,
                    count($rows),
                ),
            ];
        }

        $municipalityIds = $administrations->pluck('municipality_id')->all();
        $explanationAdministration = $explanationAdministrationId === null
            ? null
            : $administrations->firstWhere('id', $explanationAdministrationId);
        $explanationMunicipalityId = is_array($explanationAdministration)
            ? (int) $explanationAdministration['municipality_id']
            : null;
        $baselineObservations = $this->observations(
            $municipalityIds,
            array_intersect_key($baselineYears, $weights),
            $indicators,
            $explanationMunicipalityId,
        );
        $endObservations = $this->observations(
            $municipalityIds,
            array_intersect_key($endYears, $weights),
            $indicators,
            $explanationMunicipalityId,
        );
        $baselinePercentiles = $this->normalize($baselineObservations, $weights, $indicators);
        $endPercentiles = $this->normalize($endObservations, $weights, $indicators);
        $rows = [];

        foreach ($administrations as $administration) {
            $municipalityId = $administration['municipality_id'];
            $score = $this->scoreCalculator->calculate(
                $municipalityId,
                $weights,
                $this->forMunicipality($baselineObservations, $municipalityId),
                $this->forMunicipality($endObservations, $municipalityId),
                $baselinePercentiles,
                $endPercentiles,
                $minimumCoverage,
                $administration['id'] === $explanationAdministrationId,
            );

            if ($administration['id'] === $explanationAdministrationId) {
                $score['components'] = array_map(
                    function (array $component) use ($indicators): array {
                        $indicator = $indicators[$component['indicator']];

                        return [
                            ...$component,
                            'name' => $indicator->name,
                            'theme' => $indicator->theme,
                            'unit' => $indicator->unit,
                            'direction' => $indicator->rankingDirection()->value,
                        ];
                    },
                    $score['components'],
                );
            } else {
                unset($score['components']);
            }

            $rows[] = $this->baseRow(
                $administration,
                $populations[$municipalityId] ?? null,
                $populationYear,
            ) + $score;
        }

        $rows = $this->rankRows($rows);
        $ranked = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'ranked'));

        return [
            'rows' => $rows,
            'meta' => $this->meta(
                $query,
                $endpoints,
                $baselineYears,
                $endYears,
                $advancedSlugs,
                $weights,
                $globalCoverage,
                $revision,
                $ranked,
                count($rows) - $ranked,
            ),
        ];
    }

    /**
     * @param  array<int, string>  $availableSlugs
     * @param  array<string, Indicator>  $indicators
     * @return array<string, float>
     */
    private function weightsOrEmpty(array $availableSlugs, array $indicators): array
    {
        try {
            return $this->catalog->resolveWeights($availableSlugs, $indicators, [], null);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @param  array<int, float>  $populations
     * @return array<int, array{
     *     id: int,
     *     municipality_id: int,
     *     election_year: int,
     *     term_start: string|null,
     *     term_end: string|null,
     *     municipality: array{ibge_code: string, name: string, federative_unit: string},
     *     mayor: array{
     *         name: string,
     *         party_acronym: string|null,
     *         external_identifier: string|null,
     *         source_url: string|null,
     *         release: array{id: int, version: string|null, checksum_sha256: string|null, source: string|null}|null
     *     }|null
     * }>
     */
    private function administrations(
        AdministrationEvolutionQueryData $query,
        int $endYear,
        array $populations,
    ): array {
        $yearStart = "{$endYear}-01-01";
        $yearEnd = "{$endYear}-12-31";
        $records = DB::table('administrations')
            ->join('municipalities', 'municipalities.id', '=', 'administrations.municipality_id')
            ->join('federative_units', 'federative_units.id', '=', 'municipalities.federative_unit_id')
            ->leftJoin('administration_office_holders as holders', function (JoinClause $join): void {
                $join
                    ->on('holders.administration_id', '=', 'administrations.id')
                    ->where('holders.role', 'mayor');
            })
            ->leftJoin('source_releases as holder_releases', 'holder_releases.id', '=', 'holders.source_release_id')
            ->leftJoin('data_sources as holder_sources', 'holder_sources.id', '=', 'holder_releases.data_source_id')
            ->where('administrations.election_year', $query->electionYear)
            ->where(function (QueryBuilder $builder) use ($yearEnd): void {
                $builder
                    ->whereNull('municipalities.installed_at')
                    ->orWhere('municipalities.installed_at', '<=', $yearEnd);
            })
            ->where(function (QueryBuilder $builder) use ($yearStart): void {
                $builder
                    ->whereNull('municipalities.extinct_at')
                    ->orWhere('municipalities.extinct_at', '>=', $yearStart);
            })
            ->when(
                $query->federativeUnit !== null,
                fn (QueryBuilder $builder) => $builder->where(
                    'federative_units.acronym',
                    $query->federativeUnit,
                ),
            )
            ->orderBy('administrations.id')
            ->orderBy('holders.started_at')
            ->select([
                'administrations.id',
                'administrations.municipality_id',
                'administrations.election_year',
                'administrations.term_start',
                'administrations.term_end',
                'municipalities.ibge_code',
                'municipalities.name as municipality_name',
                'federative_units.acronym as federative_unit',
                'holders.id as holder_id',
                'holders.name as holder_name',
                'holders.party_acronym',
                'holders.external_identifier',
                'holders.source_url as holder_source_url',
                'holder_releases.id as holder_release_id',
                'holder_releases.version as holder_release_version',
                'holder_releases.checksum_sha256 as holder_release_checksum',
                'holder_sources.name as holder_source_name',
            ])
            ->lazy(1000);
        $administrations = [];

        foreach ($records as $record) {
            $administrationId = (int) $record->id;

            if (isset($administrations[$administrationId])) {
                continue;
            }

            $municipalityId = (int) $record->municipality_id;
            $population = $populations[$municipalityId] ?? null;

            if ($query->populationMin !== null && ($population === null || $population < $query->populationMin)) {
                continue;
            }

            if ($query->populationMax !== null && ($population === null || $population > $query->populationMax)) {
                continue;
            }

            $administrations[$administrationId] = [
                'id' => $administrationId,
                'municipality_id' => $municipalityId,
                'election_year' => (int) $record->election_year,
                'term_start' => $record->term_start === null
                    ? null
                    : mb_substr((string) $record->term_start, 0, 10),
                'term_end' => $record->term_end === null
                    ? null
                    : mb_substr((string) $record->term_end, 0, 10),
                'municipality' => [
                    'ibge_code' => (string) $record->ibge_code,
                    'name' => (string) $record->municipality_name,
                    'federative_unit' => (string) $record->federative_unit,
                ],
                'mayor' => $record->holder_id === null ? null : [
                    'name' => (string) $record->holder_name,
                    'party_acronym' => $record->party_acronym === null
                        ? null
                        : (string) $record->party_acronym,
                    'external_identifier' => $record->external_identifier === null
                        ? null
                        : (string) $record->external_identifier,
                    'source_url' => $record->holder_source_url === null
                        ? null
                        : (string) $record->holder_source_url,
                    'release' => $record->holder_release_id === null ? null : [
                        'id' => (int) $record->holder_release_id,
                        'version' => $record->holder_release_version === null
                            ? null
                            : (string) $record->holder_release_version,
                        'checksum_sha256' => $record->holder_release_checksum === null
                            ? null
                            : (string) $record->holder_release_checksum,
                        'source' => $record->holder_source_name === null
                            ? null
                            : (string) $record->holder_source_name,
                    ],
                ],
            ];
        }

        return array_values($administrations);
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
     * @param  array<int, int>  $municipalityIds
     * @param  array<string, int>  $effectiveYears
     * @param  array<string, Indicator>  $indicators
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function observations(
        array $municipalityIds,
        array $effectiveYears,
        array $indicators,
        ?int $explanationMunicipalityId,
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

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $observations
     * @param  array<string, float>  $weights
     * @param  array<string, Indicator>  $indicators
     * @return array<string, array<int, float>>
     */
    private function normalize(array $observations, array $weights, array $indicators): array
    {
        $normalized = [];

        foreach ($weights as $slug => $weight) {
            unset($weight);
            $values = [];

            foreach ($observations[$slug] ?? [] as $municipalityId => $observation) {
                $values[$municipalityId] = (float) $observation['value'];
            }

            $normalized[$slug] = $this->normalizer->normalize(
                $values,
                $indicators[$slug]->rankingDirection(),
            );
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $observations
     * @return array<string, array<string, mixed>>
     */
    private function forMunicipality(array $observations, int $municipalityId): array
    {
        $result = [];

        foreach ($observations as $slug => $indicatorObservations) {
            if (isset($indicatorObservations[$municipalityId])) {
                $result[$slug] = $indicatorObservations[$municipalityId];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $administration
     * @return array<string, mixed>
     */
    private function baseRow(array $administration, ?float $population, ?int $populationYear): array
    {
        return [
            'rank' => null,
            'administration' => [
                'id' => $administration['id'],
                'election_year' => $administration['election_year'],
                'term_start' => $administration['term_start'],
                'term_end' => $administration['term_end'],
            ],
            'mayor' => $administration['mayor'],
            'municipality' => $administration['municipality'],
            'population' => $population,
            'population_reference_year' => $populationYear,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankRows(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            if ($left['evolution_score'] === null && $right['evolution_score'] === null) {
                return [$left['municipality']['federative_unit'], $left['municipality']['name']]
                    <=> [$right['municipality']['federative_unit'], $right['municipality']['name']];
            }

            if ($left['evolution_score'] === null) {
                return 1;
            }

            if ($right['evolution_score'] === null) {
                return -1;
            }

            return $right['evolution_score'] <=> $left['evolution_score']
                ?: $left['municipality']['name'] <=> $right['municipality']['name'];
        });
        $rank = 0;
        $previousScore = null;

        foreach ($rows as $index => &$row) {
            if ($row['evolution_score'] === null) {
                continue;
            }

            if ($previousScore === null || $row['evolution_score'] !== $previousScore) {
                $rank = $index + 1;
                $previousScore = $row['evolution_score'];
            }

            $row['rank'] = $rank;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array{baseline_year: int, end_year: int}  $endpoints
     * @param  array<string, int>  $baselineYears
     * @param  array<string, int>  $endYears
     * @param  array<int, string>  $advancedSlugs
     * @param  array<string, float>  $weights
     * @return array<string, mixed>
     */
    private function meta(
        AdministrationEvolutionQueryData $query,
        array $endpoints,
        array $baselineYears,
        array $endYears,
        array $advancedSlugs,
        array $weights,
        float $globalCoverage,
        string $revision,
        int $ranked,
        int $unranked,
    ): array {
        return [
            'methodology_version' => (string) config('municipal_ranking.methodology_version'),
            'election_year' => $query->electionYear,
            'baseline_year' => $endpoints['baseline_year'],
            'end_year' => $endpoints['end_year'],
            'federative_unit' => $query->federativeUnit,
            'population_min' => $query->populationMin,
            'population_max' => $query->populationMax,
            'minimum_coverage_percent' => (float) config('municipal_ranking.minimum_coverage', 0.60) * 100,
            'global_updated_weight_percent' => $globalCoverage,
            'ranking_available' => $weights !== [],
            'weights' => $weights,
            'advanced_indicators' => $advancedSlugs,
            'baseline_effective_years' => array_intersect_key($baselineYears, array_fill_keys($advancedSlugs, true)),
            'end_effective_years' => array_intersect_key($endYears, array_fill_keys($advancedSlugs, true)),
            'data_revision' => $revision,
            'generated_at' => now()->toIso8601String(),
            'ranked_administrations' => $ranked,
            'unranked_administrations' => $unranked,
            'limitations' => [
                'general_election_winners_only',
                'substitutions_and_supplementary_elections_excluded',
                'association_does_not_establish_mayoral_causality',
            ],
        ];
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

        return hash('sha256', json_encode([
            $releases,
            Indicator::query()->max('updated_at'),
            Administration::query()->max('updated_at'),
            AdministrationOfficeHolder::query()->max('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }
}
