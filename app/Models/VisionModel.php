<?php

namespace App\Models;

use Database\Factories\VisionModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VisionModel extends Model
{
    /** @use HasFactory<VisionModelFactory> */
    use HasFactory;

    public function revisions(): HasMany
    {
        return $this->hasMany(VisionModelRevision::class)->orderByDesc('version');
    }

    public function latestRevision(): HasOne
    {
        return $this->hasOne(VisionModelRevision::class)->latestOfMany();
    }
}
