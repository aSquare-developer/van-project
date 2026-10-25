<?php

namespace App\Models;

use Database\Factories\VisionModelRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VisionModelRevision extends Model
{
    /** @use HasFactory<VisionModelRevisionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['settings' => 'array', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Create a new model/rule revision instead of changing history.');
        });
        static::deleting(function (): void {
            throw new LogicException('Model/rule revision history must be retained.');
        });
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VisionModel::class, 'vision_model_id')->withTrashed();
    }
}
