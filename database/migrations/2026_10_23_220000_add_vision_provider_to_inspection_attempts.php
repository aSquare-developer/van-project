<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table) {
            $table->string('vision_provider', 30)->default('custom');
            $table->boolean('automatic_decisions')->default(true);
            $table->string('prompt_version', 80)->nullable();
            $table->string('observed_outcome', 20)->nullable();
            $table->string('summary', 600)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table) {
            $table->dropColumn(['vision_provider', 'automatic_decisions', 'prompt_version', 'observed_outcome', 'summary']);
        });
    }
};
