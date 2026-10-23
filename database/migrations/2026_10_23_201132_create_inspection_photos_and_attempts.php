<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('path')->unique();
            $table->string('mime', 40);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->string('sha256', 64);
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['purpose', 'created_at']);
        });
        Schema::create('inspection_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_check_id')->constrained()->restrictOnDelete();
            $table->foreignId('inspection_photo_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('status', 30)->default('uploaded');
            $table->timestamps();
        });
        foreach (['inspection_template_items', 'shift_checks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('reference_photo_id')->nullable()->constrained('inspection_photos')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['shift_checks', 'inspection_template_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reference_photo_id');
            });
        }
        Schema::dropIfExists('inspection_attempts');
        Schema::dropIfExists('inspection_photos');
    }
};
