<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return ['shift_date' => 'immutable_date', 'status' => ShiftStatus::class, 'revision' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ShiftTransition::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_active || $user->role === Role::Moderator) {
            $query->whereRaw('1 = 0');
        } elseif ($user->role !== Role::Admin) {
            $query->where('worker_id', $user->id);
        }
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplate::class, 'inspection_template_id')->withTrashed();
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ShiftCheck::class)->orderBy('position');
    }
}
