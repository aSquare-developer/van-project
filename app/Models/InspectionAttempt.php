<?php

namespace App\Models;

use Database\Factories\InspectionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['submission_key', 'processing_token'])]
class InspectionAttempt extends Model
{
    /** @use HasFactory<InspectionAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime', 'analysed_at' => 'immutable_datetime',
            'boxes' => 'array', 'confidence' => 'float', 'confidence_threshold' => 'float',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ShiftCheck::class, 'shift_check_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(InspectionPhoto::class, 'inspection_photo_id');
    }
}
