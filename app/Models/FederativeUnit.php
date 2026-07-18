<?php

namespace App\Models;

use Database\Factories\FederativeUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ibge_code', 'acronym', 'name', 'region'])]
class FederativeUnit extends Model
{
    /** @use HasFactory<FederativeUnitFactory> */
    use HasFactory;

    /** @return HasMany<Municipality, $this> */
    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }
}
