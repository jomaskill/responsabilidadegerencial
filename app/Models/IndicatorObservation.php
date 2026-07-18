<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use Database\Factories\IndicatorObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property int $municipality_id
 * @property int $reference_year
 * @property string|null $value
 * @property AvailabilityStatus $availability_status
 * @property QualityStatus $quality_status
 */
#[Fillable(['observation_key', 'municipality_id', 'indicator_version_id', 'source_release_id', 'processing_run_id', 'reference_year', 'period_start', 'period_end', 'value', 'numerator', 'denominator', 'availability_status', 'quality_status', 'notes', 'metadata', 'observed_at'])]
class IndicatorObservation extends Model
{
    /** @use HasFactory<IndicatorObservationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Municipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /** @return BelongsTo<IndicatorVersion, $this> */
    public function indicatorVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorVersion::class);
    }

    /** @return BelongsTo<SourceRelease, $this> */
    public function sourceRelease(): BelongsTo
    {
        return $this->belongsTo(SourceRelease::class);
    }

    /** @return BelongsTo<ProcessingRun, $this> */
    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    /** @return HasMany<ObservationFlag, $this> */
    public function flags(): HasMany
    {
        return $this->hasMany(ObservationFlag::class);
    }

    /** @return HasMany<ObservationInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(ObservationInput::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Indicator observations are immutable. Create a new source release instead.'));
        static::deleting(fn () => throw new RuntimeException('Indicator observations are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'availability_status' => AvailabilityStatus::class,
            'quality_status' => QualityStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'metadata' => 'array',
            'observed_at' => 'immutable_datetime',
        ];
    }
}
