<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Database\Factories\ShiftCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftCheck extends Model
{
    /** @use HasFactory<ShiftCheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'position' => 'integer', 'status' => CheckStatus::class];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InspectionAttempt::class)->latest('id');
    }

    public function referencePhoto(): BelongsTo
    {
        return $this->belongsTo(InspectionPhoto::class, 'reference_photo_id');
    }
}
