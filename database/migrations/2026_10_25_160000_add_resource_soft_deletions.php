<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vans', 'shifts', 'inspection_templates', 'vision_models'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['vans', 'shifts', 'inspection_templates', 'vision_models'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
