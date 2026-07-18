<?php

use App\Enums\AvailabilityStatus;
use App\Enums\ProcessingStatus;
use App\Enums\QualityStatus;
use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\Municipality;
use App\Models\ObservationInput;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use Database\Seeders\DatabaseSeeder;

test('homicide rate is calculated with traceable population and death inputs', function () {
    $this->seed(DatabaseSeeder::class);
    $municipality = Municipality::query()->create([
        'federative_unit_id' => FederativeUnit::query()->where('acronym', 'SP')->value('id'),
        'ibge_code' => '3550308',
        'name' => 'São Paulo',
        'normalized_name' => 'sao paulo',
        'is_active' => true,
    ]);
    $source = DataSource::query()->where('slug', 'manual-compiled')->firstOrFail();
    $release = SourceRelease::query()->create([
        'data_source_id' => $source->id,
        'reference_year' => 2024,
        'version' => 'fixture-v1',
        'status' => ReleaseStatus::Final,
        'collected_at' => now()->toDateString(),
    ]);
    $run = ProcessingRun::query()->create([
        'data_source_id' => $source->id,
        'source_release_id' => $release->id,
        'type' => 'fixture',
        'status' => ProcessingStatus::Completed,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    foreach (['population' => 1_000_000, 'homicide_count' => 10] as $slug => $value) {
        $version = IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', $slug))
            ->firstOrFail();

        IndicatorObservation::query()->create([
            'observation_key' => hash('sha256', "{$slug}-2024"),
            'municipality_id' => $municipality->id,
            'indicator_version_id' => $version->id,
            'source_release_id' => $release->id,
            'processing_run_id' => $run->id,
            'reference_year' => 2024,
            'value' => $value,
            'availability_status' => AvailabilityStatus::Available,
            'quality_status' => QualityStatus::Accepted,
        ]);
    }

    $this->artisan('data:recalculate', [
        'indicator' => 'homicide_rate',
        '--from' => '2024',
        '--to' => '2024',
    ])->assertSuccessful();

    $rate = IndicatorObservation::query()
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'homicide_rate'))
        ->sole();

    expect((float) $rate->value)->toBe(1.0)
        ->and((float) $rate->numerator)->toBe(10.0)
        ->and((float) $rate->denominator)->toBe(1_000_000.0)
        ->and(ObservationInput::query()->where('indicator_observation_id', $rate->id)->count())->toBe(2);

    $this->artisan('data:recalculate', [
        'indicator' => 'homicide_rate',
        '--from' => '2024',
        '--to' => '2024',
    ])->assertSuccessful();

    expect(IndicatorObservation::query()
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->where('slug', 'homicide_rate'))
        ->count())->toBe(1);
});
