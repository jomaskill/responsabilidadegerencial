<?php

namespace App\Models;

use Database\Factories\MunicipalityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['federative_unit_id', 'ibge_code', 'name', 'normalized_name', 'is_active', 'installed_at', 'extinct_at'])]
class Municipality extends Model
{
    /** @use HasFactory<MunicipalityFactory> */
    use HasFactory;

    /** @return BelongsTo<FederativeUnit, $this> */
    public function federativeUnit(): BelongsTo
    {
        return $this->belongsTo(FederativeUnit::class);
    }

    /** @return HasMany<MunicipalityIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(MunicipalityIdentifier::class);
    }

    /** @return HasMany<Administration, $this> */
    public function administrations(): HasMany
    {
        return $this->hasMany(Administration::class);
    }

    /** @return HasMany<IndicatorObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(IndicatorObservation::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'installed_at' => 'date',
            'extinct_at' => 'date',
        ];
    }
}
