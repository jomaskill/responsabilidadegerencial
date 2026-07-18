<?php

namespace App\Models;

use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'publisher', 'acquisition_method', 'homepage_url', 'configuration', 'is_active'])]
class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;

    /** @return HasMany<SourceRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(SourceRelease::class);
    }

    /** @return HasMany<ProcessingRun, $this> */
    public function processingRuns(): HasMany
    {
        return $this->hasMany(ProcessingRun::class);
    }

    protected function casts(): array
    {
        return ['configuration' => 'array', 'is_active' => 'boolean'];
    }
}
