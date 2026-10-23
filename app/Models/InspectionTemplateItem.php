<?php

namespace App\Models;

use Database\Factories\InspectionTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'title', 'instructions', 'check_key', 'expected_class', 'is_required'])]
class InspectionTemplateItem extends Model
{
    /** @use HasFactory<InspectionTemplateItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'position' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplate::class, 'inspection_template_id');
    }
}
