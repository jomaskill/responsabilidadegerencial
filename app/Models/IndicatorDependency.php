<?php

namespace App\Models;

use Database\Factories\IndicatorDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['indicator_version_id', 'depends_on_indicator_version_id', 'role'])]
class IndicatorDependency extends Model
{
    /** @use HasFactory<IndicatorDependencyFactory> */
    use HasFactory;

    /** @return BelongsTo<IndicatorVersion, $this> */
    public function indicatorVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorVersion::class);
    }

    /** @return BelongsTo<IndicatorVersion, $this> */
    public function dependency(): BelongsTo
    {
        return $this->belongsTo(IndicatorVersion::class, 'depends_on_indicator_version_id');
    }
}
