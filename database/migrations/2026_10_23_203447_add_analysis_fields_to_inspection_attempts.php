<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table) {
            $table->string('check_key')->nullable();
            $table->string('expected_class')->nullable();
            $table->string('absent_class')->nullable();
            $table->string('model_version')->nullable();
            $table->float('confidence_threshold')->default(0.8);
            $table->unsignedTinyInteger('analysis_runs')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('analysed_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('result', 30)->nullable();
            $table->string('detected_class')->nullable();
            $table->float('confidence')->nullable();
            $table->json('boxes')->nullable();
            $table->index(['status', 'dispatched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table) {
            $table->dropIndex(['status', 'dispatched_at']);
            $table->dropColumn(['check_key', 'expected_class', 'absent_class', 'model_version', 'confidence_threshold', 'analysis_runs', 'dispatched_at', 'started_at', 'retry_at', 'analysed_at', 'processing_token', 'error_code', 'result', 'detected_class', 'confidence', 'boxes']);
        });
    }
};
