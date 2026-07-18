<?php

namespace App\Models;

use Database\Factories\ReferenceSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'unit', 'source_url'])]
class ReferenceSeries extends Model
{
    /** @use HasFactory<ReferenceSeriesFactory> */
    use HasFactory;

    /** @return HasMany<ReferenceSeriesValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ReferenceSeriesValue::class);
    }
}
