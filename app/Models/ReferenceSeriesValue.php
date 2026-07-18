<?php

namespace App\Models;

use Database\Factories\ReferenceSeriesValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference_series_id', 'reference_date', 'value', 'release_version'])]
class ReferenceSeriesValue extends Model
{
    /** @use HasFactory<ReferenceSeriesValueFactory> */
    use HasFactory;

    /** @return BelongsTo<ReferenceSeries, $this> */
    public function referenceSeries(): BelongsTo
    {
        return $this->belongsTo(ReferenceSeries::class);
    }

    protected function casts(): array
    {
        return ['reference_date' => 'date'];
    }
}
