<?php

namespace App\Models;

use Database\Factories\ObservationInputFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['indicator_observation_id', 'input_indicator_observation_id', 'role'])]
class ObservationInput extends Model
{
    /** @use HasFactory<ObservationInputFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<IndicatorObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(IndicatorObservation::class, 'indicator_observation_id');
    }

    /** @return BelongsTo<IndicatorObservation, $this> */
    public function inputObservation(): BelongsTo
    {
        return $this->belongsTo(IndicatorObservation::class, 'input_indicator_observation_id');
    }
}
