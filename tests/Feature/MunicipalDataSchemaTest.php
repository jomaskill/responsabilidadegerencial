<?php

use App\Models\DataSource;
use App\Models\FederativeUnit;
use App\Models\Indicator;
use App\Models\IndicatorDependency;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Schema;

test('municipal data schema and initial catalog are installed', function () {
    $this->seed(DatabaseSeeder::class);

    expect(FederativeUnit::query()->count())->toBe(27)
        ->and(DataSource::query()->count())->toBe(13)
        ->and(Indicator::query()->count())->toBe(14)
        ->and(IndicatorDependency::query()->count())->toBe(5);

    expect(Schema::hasColumns('indicator_observations', [
        'observation_key',
        'municipality_id',
        'indicator_version_id',
        'source_release_id',
        'processing_run_id',
        'reference_year',
        'value',
        'availability_status',
        'quality_status',
    ]))->toBeTrue();

    expect(Schema::hasColumns('administration_office_holders', [
        'source_release_id',
        'external_identifier',
    ]))->toBeTrue();

    expect(Schema::hasTable('ranking_definitions'))->toBeFalse()
        ->and(Schema::hasTable('ranking_definition_indicators'))->toBeFalse()
        ->and(Schema::hasTable('ranking_runs'))->toBeFalse()
        ->and(Schema::hasTable('ranking_scores'))->toBeFalse()
        ->and(Schema::hasTable('ranking_score_components'))->toBeFalse()
        ->and(Schema::hasTable('observation_inputs'))->toBeTrue()
        ->and(Schema::hasTable('processing_errors'))->toBeTrue();
});
