<?php

namespace App\Support\MunicipalRanking;

use App\Enums\IndicatorDirection;
use App\Models\Indicator;
use InvalidArgumentException;

class RankingMethodologyCatalog
{
    /** @return array<int, string> */
    public function themes(): array
    {
        return array_keys((array) config('municipal_ranking.themes', []));
    }

    /** @return array<int, string> */
    public function customWeightIndicators(): array
    {
        return array_values((array) config('municipal_ranking.custom_weight_indicators', []));
    }

    /** @return array<int, string> */
    public function contextIndicators(): array
    {
        return array_values((array) config('municipal_ranking.context_indicators', []));
    }

    public function isEligible(Indicator $indicator): bool
    {
        return $indicator->is_active
            && $indicator->rankingDirection() !== IndicatorDirection::ContextOnly
            && in_array($indicator->slug, $this->customWeightIndicators(), true);
    }

    /**
     * @param  array<int, string>  $availableSlugs
     * @param  array<string, Indicator>  $indicators
     * @param  array<string, float>  $requestedWeights
     * @return array<string, float>
     */
    public function resolveWeights(
        array $availableSlugs,
        array $indicators,
        array $requestedWeights,
        ?string $theme,
    ): array {
        if ($theme !== null && ! in_array($theme, $this->themes(), true)) {
            throw new InvalidArgumentException("Unknown ranking theme: {$theme}.");
        }

        if ($requestedWeights !== []) {
            return $this->resolveRequestedWeights($availableSlugs, $indicators, $requestedWeights, $theme);
        }

        return $this->defaultWeights($availableSlugs, $indicators, $theme);
    }

    /**
     * @param  array<int, string>  $availableSlugs
     * @param  array<string, Indicator>  $indicators
     * @param  array<string, float>  $requestedWeights
     * @return array<string, float>
     */
    private function resolveRequestedWeights(
        array $availableSlugs,
        array $indicators,
        array $requestedWeights,
        ?string $theme,
    ): array {
        $usable = [];

        foreach ($requestedWeights as $slug => $weight) {
            $indicator = $indicators[$slug] ?? null;

            if ($indicator === null || ! $this->isEligible($indicator)) {
                throw new InvalidArgumentException("Indicator cannot be used for ranking: {$slug}.");
            }

            if ($theme !== null && $indicator->theme !== $theme) {
                throw new InvalidArgumentException("Indicator {$slug} does not belong to theme {$theme}.");
            }

            if ($weight > 0 && in_array($slug, $availableSlugs, true)) {
                $usable[$slug] = $weight;
            }
        }

        if ($usable === []) {
            throw new InvalidArgumentException('None of the weighted indicators has published data for this exercise.');
        }

        return $this->normalizeWeights($usable);
    }

    /**
     * @param  array<int, string>  $availableSlugs
     * @param  array<string, Indicator>  $indicators
     * @return array<string, float>
     */
    private function defaultWeights(array $availableSlugs, array $indicators, ?string $selectedTheme): array
    {
        $configuredThemes = (array) config('municipal_ranking.themes', []);
        $resolvedThemes = [];

        foreach ($configuredThemes as $theme => $dimensions) {
            if ($selectedTheme !== null && $theme !== $selectedTheme) {
                continue;
            }

            $resolved = [];

            foreach ($dimensions as $candidates) {
                foreach ((array) $candidates as $slug) {
                    $indicator = $indicators[$slug] ?? null;

                    if ($indicator !== null && $this->isEligible($indicator) && in_array($slug, $availableSlugs, true)) {
                        $resolved[] = $slug;

                        break;
                    }
                }
            }

            if ($resolved !== []) {
                $resolvedThemes[$theme] = array_values(array_unique($resolved));
            }
        }

        if ($resolvedThemes === []) {
            throw new InvalidArgumentException('No ranking indicators have published data for this exercise.');
        }

        $themeWeight = 100 / count($resolvedThemes);
        $weights = [];

        foreach ($resolvedThemes as $slugs) {
            $indicatorWeight = $themeWeight / count($slugs);

            foreach ($slugs as $slug) {
                $weights[$slug] = $indicatorWeight;
            }
        }

        return $this->normalizeWeights($weights);
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function normalizeWeights(array $weights): array
    {
        $total = array_sum($weights);

        foreach ($weights as $slug => $weight) {
            $weights[$slug] = round(($weight / $total) * 100, 8);
        }

        ksort($weights);

        return $weights;
    }
}
