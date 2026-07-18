<?php

namespace App\Models;

use Database\Factories\MunicipalityIdentifierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['municipality_id', 'scheme', 'value', 'valid_from', 'valid_to'])]
class MunicipalityIdentifier extends Model
{
    /** @use HasFactory<MunicipalityIdentifierFactory> */
    use HasFactory;

    /** @return BelongsTo<Municipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }
}
