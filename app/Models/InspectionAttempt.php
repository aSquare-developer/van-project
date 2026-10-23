<?php

namespace App\Models;

use Database\Factories\InspectionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['submission_key'])]
class InspectionAttempt extends Model
{
    /** @use HasFactory<InspectionAttemptFactory> */
    use HasFactory;

    public function check(): BelongsTo
    {
        return $this->belongsTo(ShiftCheck::class, 'shift_check_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(InspectionPhoto::class, 'inspection_photo_id');
    }
}
