<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });
        Schema::create('vision_model_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vision_model_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('settings');
            $table->timestamps();
            $table->unique(['vision_model_id', 'version']);
        });
        Schema::table('inspection_template_items', function (Blueprint $table): void {
            $table->foreignId('vision_model_revision_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('shift_checks', function (Blueprint $table): void {
            $table->json('analysis_config')->nullable();
        });
        Schema::table('inspection_attempts', function (Blueprint $table): void {
            $table->json('analysis_config')->nullable();
            $table->string('observed_outcome', 20)->nullable();
            $table->string('decision_mode', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table): void {
            $table->dropColumn(['analysis_config', 'observed_outcome', 'decision_mode']);
        });
        Schema::table('shift_checks', function (Blueprint $table): void {
            $table->dropColumn('analysis_config');
        });
        Schema::table('inspection_template_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vision_model_revision_id');
        });
        Schema::dropIfExists('vision_model_revisions');
        Schema::dropIfExists('vision_models');
    }
};
