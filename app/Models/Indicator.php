<?php

namespace App\Models;

use App\Enums\IndicatorDirection;
use App\Enums\Periodicity;
use Database\Factories\IndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'description', 'theme', 'unit', 'direction', 'periodicity', 'aggregation_method', 'is_derived', 'is_active'])]
class Indicator extends Model
{
    /** @use HasFactory<IndicatorFactory> */
    use HasFactory;

    /** @return HasMany<IndicatorVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(IndicatorVersion::class);
    }

    public function rankingDirection(): IndicatorDirection
    {
        $direction = $this->getAttribute('direction');

        return $direction instanceof IndicatorDirection
            ? $direction
            : IndicatorDirection::from((string) $direction);
    }

    public function periodicityValue(): Periodicity
    {
        $periodicity = $this->getAttribute('periodicity');

        return $periodicity instanceof Periodicity
            ? $periodicity
            : Periodicity::from((string) $periodicity);
    }

    protected function casts(): array
    {
        return [
            'direction' => IndicatorDirection::class,
            'periodicity' => Periodicity::class,
            'is_derived' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
