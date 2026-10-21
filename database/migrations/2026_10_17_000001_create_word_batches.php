<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite/Turso do not support adding check constraints after a table is
        // created. Define the two invariant-bearing tables directly so both the
        // production database and the test database reject partial cohorts.
        DB::statement("CREATE TABLE word_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scheduled_for DATETIME NOT NULL UNIQUE,
            target_count INTEGER NOT NULL DEFAULT 5 CHECK (target_count = 5),
            status VARCHAR NOT NULL DEFAULT 'generating',
            generated_at DATETIME NULL, ready_at DATETIME NULL, published_at DATETIME NULL,
            notification_dispatched_at DATETIME NULL, last_progressed_at DATETIME NULL,
            attempts INTEGER NOT NULL DEFAULT 0, retry_at DATETIME NULL, last_error TEXT NULL,
            stalled_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL
        )");
        DB::statement('CREATE INDEX word_batches_status_retry_at_index ON word_batches (status, retry_at)');

        DB::statement("CREATE TABLE word_batch_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word_batch_id INTEGER NOT NULL REFERENCES word_batches(id) ON DELETE CASCADE,
            slot_number INTEGER NOT NULL CHECK (slot_number BETWEEN 1 AND 5),
            word_id INTEGER NULL UNIQUE REFERENCES words(id) ON DELETE SET NULL,
            status VARCHAR NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
            retry_at DATETIME NULL, last_error TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE (word_batch_id, slot_number)
        )");
        DB::statement('CREATE INDEX word_batch_slots_status_retry_at_index ON word_batch_slots (status, retry_at)');

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
