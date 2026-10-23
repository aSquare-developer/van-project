<?php

namespace App\Models;

use Database\Factories\InspectionTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
class InspectionTemplate extends Model
{
    /** @use HasFactory<InspectionTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionTemplateItem::class)->orderBy('position');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
