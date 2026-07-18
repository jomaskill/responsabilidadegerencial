<?php

namespace App\Models;

use Database\Factories\IndicatorVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['indicator_id', 'version', 'valid_from', 'valid_to', 'formula', 'methodology_url', 'notes'])]
class IndicatorVersion extends Model
{
    /** @use HasFactory<IndicatorVersionFactory> */
    use HasFactory;

    /** @return BelongsTo<Indicator, $this> */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /** @return HasMany<IndicatorDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(IndicatorDependency::class);
    }

    /** @return HasMany<IndicatorObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(IndicatorObservation::class);
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }
}
