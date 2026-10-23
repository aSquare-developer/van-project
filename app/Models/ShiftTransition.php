<?php

namespace App\Models;

use App\Enums\ShiftDecision;
use App\Enums\ShiftStatus;
use Database\Factories\ShiftTransitionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['request_key'])]
class ShiftTransition extends Model
{
    /** @use HasFactory<ShiftTransitionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'decision' => ShiftDecision::class, 'from_status' => ShiftStatus::class,
            'to_status' => ShiftStatus::class, 'snapshot' => 'array',
            'revision_before' => 'integer', 'revision_after' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
