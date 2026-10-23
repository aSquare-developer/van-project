<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('registration', 32)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('inspection_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('inspection_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('instructions');
            $table->string('check_key', 100);
            $table->string('expected_class', 100);
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['inspection_template_id', 'position'], 'template_item_position_unique');
        });
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->date('shift_date');
            $table->foreignId('worker_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('van_id')->constrained()->restrictOnDelete();
            $table->foreignId('inspection_template_id')->constrained()->restrictOnDelete();
            $table->string('template_name');
            $table->string('van_name');
            $table->string('van_registration', 32)->nullable();
            $table->string('status', 40)->default('assigned');
            $table->timestamps();
            $table->index(['worker_id', 'shift_date']);
        });
        Schema::create('shift_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('instructions');
            $table->string('check_key', 100);
            $table->string('expected_class', 100);
            $table->boolean('is_required');
            $table->string('status', 40)->default('pending');
            $table->timestamps();
            $table->unique(['shift_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_checks');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('inspection_template_items');
        Schema::dropIfExists('inspection_templates');
        Schema::dropIfExists('vans');
    }
};
