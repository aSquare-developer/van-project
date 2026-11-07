<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('detected_count')->nullable()->after('detected_class');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_attempts', function (Blueprint $table): void {
            $table->dropColumn('detected_count');
        });
    }
};
