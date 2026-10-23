<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Database\Factories\ShiftCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
