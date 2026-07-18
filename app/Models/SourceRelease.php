<?php

namespace App\Models;

use App\Enums\ReleaseStatus;
use Database\Factories\SourceReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['data_source_id', 'reference_year', 'version', 'status', 'published_at', 'collected_at', 'source_url', 'artifact_disk', 'artifact_path', 'checksum_sha256', 'mime_type', 'size_bytes', 'metadata', 'superseded_by_id'])]
class SourceRelease extends Model
{
    /** @use HasFactory<SourceReleaseFactory> */
    use HasFactory;

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** @return BelongsTo<SourceRelease, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** @return HasMany<IndicatorObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(IndicatorObservation::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'published_at' => 'date',
            'collected_at' => 'date',
            'metadata' => 'array',
        ];
    }
}
