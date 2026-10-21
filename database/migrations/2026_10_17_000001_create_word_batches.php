<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_batches', function (Blueprint $table) {
            $table->id();
            $table->timestamp('scheduled_for')->unique();
            $table->unsignedTinyInteger('target_count')->default(5);
            $table->string('status')->default('generating');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('notification_dispatched_at')->nullable();
            $table->timestamp('last_progressed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->json('last_error')->nullable();
            $table->timestamp('stalled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'retry_at']);
        });

        Schema::create('word_batch_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->foreignId('word_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->json('last_error')->nullable();
            $table->timestamps();
            $table->unique(['word_batch_id', 'slot_number']);
            $table->index(['status', 'retry_at']);
        });

        Schema::create('word_batch_run_locks', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->uuid('token');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('words', function (Blueprint $table) {
            $table->string('pronunciation_status')->default('pending');
            $table->timestamp('pronunciation_attempted_at')->nullable();
            $table->unsignedInteger('pronunciation_attempts')->default(0);
            $table->timestamp('pronunciation_retry_at')->nullable();
            $table->string('pronunciation_error', 255)->nullable();
            $table->unsignedInteger('dictionary_attempts')->default(0);
            $table->timestamp('dictionary_retry_at')->nullable();
            $table->string('dictionary_error', 255)->nullable();
            $table->unsignedInteger('ai_definition_attempts')->default(0);
            $table->timestamp('ai_definition_retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn(['pronunciation_status', 'pronunciation_attempted_at', 'pronunciation_attempts', 'pronunciation_retry_at', 'pronunciation_error', 'dictionary_attempts', 'dictionary_retry_at', 'dictionary_error', 'ai_definition_attempts', 'ai_definition_retry_at']);
        });
        Schema::dropIfExists('word_batch_run_locks');
        Schema::dropIfExists('word_batch_slots');
        Schema::dropIfExists('word_batches');
    }
};
