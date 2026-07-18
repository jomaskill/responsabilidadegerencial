<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Database\Factories\ProcessingRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['data_source_id', 'source_release_id', 'type', 'status', 'started_at', 'finished_at', 'input_rows', 'accepted_rows', 'rejected_rows', 'parameters', 'error_summary'])]
class ProcessingRun extends Model
{
    /** @use HasFactory<ProcessingRunFactory> */
    use HasFactory;

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** @return BelongsTo<SourceRelease, $this> */
    public function sourceRelease(): BelongsTo
    {
        return $this->belongsTo(SourceRelease::class);
    }

    /** @return HasMany<ProcessingError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(ProcessingError::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ProcessingStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'parameters' => 'array',
        ];
    }
}
