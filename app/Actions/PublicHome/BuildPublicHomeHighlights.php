<?php

namespace App\Actions\PublicHome;

use App\Actions\MunicipalRanking\CalculateAdministrationEvolutionRanking;
use App\Actions\MunicipalRanking\CalculateMunicipalRanking;
use App\DTO\MunicipalRanking\AdministrationEvolutionQueryData;
use App\DTO\MunicipalRanking\RankingQueryData;
use App\Enums\AvailabilityStatus;
use App\Enums\IndicatorDirection;
use App\Enums\QualityStatus;
use App\Models\Administration;
use App\Models\AdministrationOfficeHolder;
use App\Models\Indicator;
use App\Models\Municipality;
use App\Models\SourceRelease;
use App\Support\MunicipalRanking\NationalIndicatorCoverage;
use App\Support\MunicipalRanking\RankingMethodologyCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BuildPublicHomeHighlights
{
    public function __construct(
        private readonly CalculateMunicipalRanking $municipalRanking,
        private readonly CalculateAdministrationEvolutionRanking $administrationRanking,
        private readonly RankingMethodologyCatalog $catalog,
        private readonly NationalIndicatorCoverage $nationalCoverage,
    ) {}

    /** @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function consolidated(int $year): array
    {
        /** @var array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} $result */
        $result = $this->remember(
            "consolidated:{$year}",
            function () use ($year): array {
                $ranking = $this->municipalRanking->execute(
                    new RankingQueryData(year: $year, perPage: 5),
                );

                return ['rows' => $ranking->rows, 'meta' => $ranking->meta];
            },
        );

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function indicators(int $year): array
    {
        /** @var array<int, array<string, mixed>> $result */
        $result = $this->remember("indicators:{$year}", fn (): array => $this->buildIndicatorHighlights($year));

        return $result;
    }

    /** @return array{selected: array<string, mixed>, current: array<string, mixed>} */
    public function administrations(int $electionYear = 2020): array
    {
        return [
            'selected' => $this->administration($electionYear),
            'current' => $this->administration(2024),
        ];
    }

    /** @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function administration(int $electionYear): array
    {
        /** @var array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>} $result */
        $result = $this->remember("administration:{$electionYear}", function () use ($electionYear): array {
            $ranking = $this->administrationRanking->execute(
                new AdministrationEvolutionQueryData(electionYear: $electionYear, perPage: 5),
            );

            return ['rows' => $ranking->rows, 'meta' => $ranking->meta];
        });

        return $result;
    }

    /** @return array<string, mixed> */
    public function freshness(int $year): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->remember("freshness:{$year}", function () use ($year): array {
            $latestRelease = SourceRelease::query()
                ->with('dataSource')
                ->whereNull('superseded_by_id')
                ->where('reference_year', '<=', $year)
                ->orderByDesc('collected_at')
                ->orderByDesc('id')
                ->first();
            $latestCollectionDate = $latestRelease?->getRawOriginal('collected_at');

            return [
                'latest_collection_date' => is_string($latestCollectionDate)
                    ? mb_substr($latestCollectionDate, 0, 10)
                    : null,
                'latest_source' => $latestRelease?->dataSource?->name,
                'active_releases' => SourceRelease::query()->whereNull('superseded_by_id')->count(),
                'official_sources' => SourceRelease::query()
                    ->whereNull('superseded_by_id')
                    ->distinct()
                    ->count('data_source_id'),
                'municipalities' => Municipality::query()->existingInYear($year)->count(),
            ];
        });

        return $result;
    }

    /**
     * @param  callable(): array<mixed>  $callback
     * @return array<mixed>
     */
    private function remember(string $suffix, callable $callback): array
    {
        $key = 'public-home:'.hash('sha256', json_encode([
            'suffix' => $suffix,
            'payload_version' => config('municipal_ranking.cache_payload_version', 1),
            'methodology' => config('municipal_ranking.methodology_version'),
            'revision' => $this->dataRevision(),
        ], JSON_THROW_ON_ERROR));

        /** @var array<mixed> $result */
        $result = Cache::flexible(
            $key,
            [
                (int) config('municipal_ranking.cache.fresh_seconds', 600),
                (int) config('municipal_ranking.cache.stale_seconds', 1800),
            ],
            $callback,
        );

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildIndicatorHighlights(int $year): array
    {
        $eligibleSlugs = $this->catalog->customWeightIndicators();
        $indicators = Indicator::query()
            ->whereIn('slug', $eligibleSlugs)
            ->where('is_active', true)
            ->orderByRaw("CASE theme WHEN 'economia' THEN 1 WHEN 'educacao' THEN 2 WHEN 'saneamento' THEN 3 WHEN 'seguranca' THEN 4 ELSE 5 END")
            ->orderBy('name')
            ->get()
            ->keyBy('slug');
        $effectiveYears = $this->nationalCoverage->completeEffectiveYears(
            $year,
            $indicators->keys()->all(),
        );
        $indicators = $indicators->filter(
            fn (Indicator $indicator): bool => isset($effectiveYears[$indicator->slug]),
        );
        $top = [];

        if ($effectiveYears !== []) {
            $records = DB::table('indicator_observations')
                ->join('indicator_versions', 'indicator_versions.id', '=', 'indicator_observations.indicator_version_id')
                ->join('indicators', 'indicators.id', '=', 'indicator_versions.indicator_id')
                ->join('source_releases', 'source_releases.id', '=', 'indicator_observations.source_release_id')
                ->join('municipalities', 'municipalities.id', '=', 'indicator_observations.municipality_id')
                ->join('federative_units', 'federative_units.id', '=', 'municipalities.federative_unit_id')
                ->whereNull('source_releases.superseded_by_id')
                ->where('indicator_observations.quality_status', QualityStatus::Accepted->value)
                ->whereIn('indicator_observations.availability_status', [
                    AvailabilityStatus::Available->value,
                    AvailabilityStatus::Provisional->value,
                ])
                ->where(function ($builder) use ($effectiveYears): void {
                    foreach ($effectiveYears as $slug => $effectiveYear) {
                        $builder->orWhere(function ($indicatorQuery) use ($slug, $effectiveYear): void {
                            $indicatorQuery
                                ->where('indicators.slug', $slug)
                                ->where('indicator_observations.reference_year', $effectiveYear);
                        });
                    }
                })
                ->select([
                    'indicators.slug',
                    'indicator_observations.municipality_id',
                    'indicator_observations.value',
                    'municipalities.ibge_code',
                    'municipalities.name',
                    'federative_units.acronym as federative_unit',
                ])
                ->orderBy('indicator_observations.id')
                ->lazy(1000);

            foreach ($records as $record) {
                $slug = (string) $record->slug;
                $municipalityId = (int) $record->municipality_id;

                $top[$slug][$municipalityId] = [
                    'ibge_code' => (string) $record->ibge_code,
                    'name' => (string) $record->name,
                    'federative_unit' => (string) $record->federative_unit,
                    'value' => (float) $record->value,
                ];
                $direction = $indicators[$slug]->rankingDirection();
                uasort($top[$slug], fn (array $left, array $right): int => $this->compareIndicatorRows(
                    $left,
                    $right,
                    $direction,
                ));
                $top[$slug] = array_slice($top[$slug], 0, 3, true);
            }
        }

        return $indicators
            ->map(function (Indicator $indicator) use ($year, $effectiveYears, $top): array {
                $effectiveYear = $effectiveYears[$indicator->slug] ?? null;

                return [
                    'slug' => $indicator->slug,
                    'name' => $indicator->name,
                    'description' => $indicator->description,
                    'theme' => $indicator->theme,
                    'unit' => $indicator->unit,
                    'direction' => $indicator->rankingDirection()->value,
                    'effective_year' => $effectiveYear,
                    'coverage_percent' => 100.0,
                    'status' => 'available',
                    'leaders' => array_values($top[$indicator->slug] ?? []),
                    'ranking_url' => route('public.ranking', [
                        'year' => $year,
                        'weights' => [$indicator->slug => 100],
                    ]),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $left
     * @param  array<string, mixed>  $right
     */
    private function compareIndicatorRows(
        array $left,
        array $right,
        IndicatorDirection $direction,
    ): int {
        $comparison = $direction === IndicatorDirection::LowerIsBetter
            ? (float) $left['value'] <=> (float) $right['value']
            : (float) $right['value'] <=> (float) $left['value'];

        return $comparison ?: (string) $left['name'] <=> (string) $right['name'];
    }

    private function dataRevision(): string
    {
        return hash('sha256', json_encode([
            SourceRelease::query()->whereNull('superseded_by_id')->max('updated_at'),
            SourceRelease::query()->whereNull('superseded_by_id')->count(),
            Indicator::query()->max('updated_at'),
            Administration::query()->max('updated_at'),
            AdministrationOfficeHolder::query()->max('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }
}
