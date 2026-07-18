<?php

namespace App\Models;

use Database\Factories\AdministrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['municipality_id', 'election_year', 'term_start', 'term_end', 'status'])]
class Administration extends Model
{
    /** @use HasFactory<AdministrationFactory> */
    use HasFactory;

    /** @return BelongsTo<Municipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /** @return HasMany<AdministrationOfficeHolder, $this> */
    public function officeHolders(): HasMany
    {
        return $this->hasMany(AdministrationOfficeHolder::class);
    }

    protected function casts(): array
    {
        return ['term_start' => 'date', 'term_end' => 'date'];
    }
}
