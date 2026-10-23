<?php

namespace App\Models;

use Database\Factories\InspectionPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Hidden(['path', 'sha256'])]
class InspectionPhoto extends Model
{
    /** @use HasFactory<InspectionPhotoFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['purged_at' => 'immutable_datetime', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }

    public function attempt(): HasOne
    {
        return $this->hasOne(InspectionAttempt::class);
    }

    public function templateItems(): HasMany
    {
        return $this->hasMany(InspectionTemplateItem::class, 'reference_photo_id');
    }

    public function shiftChecks(): HasMany
    {
        return $this->hasMany(ShiftCheck::class, 'reference_photo_id');
    }
}
