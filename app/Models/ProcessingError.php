<?php

namespace App\Models;

use Database\Factories\ProcessingErrorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['processing_run_id', 'row_number', 'municipality_code', 'indicator_slug', 'code', 'message', 'payload'])]
class ProcessingError extends Model
{
    /** @use HasFactory<ProcessingErrorFactory> */
    use HasFactory;

    /** @return BelongsTo<ProcessingRun, $this> */
    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
