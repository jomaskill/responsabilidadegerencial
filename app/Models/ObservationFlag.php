<?php

namespace App\Models;

use Database\Factories\ObservationFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['indicator_observation_id', 'code', 'severity', 'message', 'details'])]
class ObservationFlag extends Model
{
    /** @use HasFactory<ObservationFlagFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<IndicatorObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(IndicatorObservation::class, 'indicator_observation_id');
    }

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
