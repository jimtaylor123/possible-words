<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_review_actions', function (Blueprint $table) {
            $table->id();
            // Audit history is intentionally preserved when a report or admin account is
            // removed, matching the reports table's nullOnDelete columns. The queue payload
            // renders deleted accounts as "Deleted user" and keeps the action record.
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at', 'id']);
            $table->index('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_review_actions');
    }
};
