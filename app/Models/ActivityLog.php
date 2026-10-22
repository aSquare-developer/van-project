<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'actor_id', 'subject_id', 'metadata'])]
class ActivityLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
