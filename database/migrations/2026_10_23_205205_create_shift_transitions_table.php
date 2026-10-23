<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('shift_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('decision', 30);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->unsignedInteger('revision_before');
            $table->unsignedInteger('revision_after');
            $table->text('comment')->nullable();
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['shift_id', 'request_key']);
            $table->index(['shift_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_transitions');
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['revision', 'completed_at']);
        });
    }
};
