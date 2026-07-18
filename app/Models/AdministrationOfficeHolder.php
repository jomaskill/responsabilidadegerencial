<?php

namespace App\Models;

use Database\Factories\AdministrationOfficeHolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['administration_id', 'name', 'role', 'party_acronym', 'started_at', 'ended_at', 'source_url'])]
class AdministrationOfficeHolder extends Model
{
    /** @use HasFactory<AdministrationOfficeHolderFactory> */
    use HasFactory;

    /** @return BelongsTo<Administration, $this> */
    public function administration(): BelongsTo
    {
        return $this->belongsTo(Administration::class);
    }

    protected function casts(): array
    {
        return ['started_at' => 'date', 'ended_at' => 'date'];
    }
}
