<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->morphs('reportable');
            $table->string('reason');
            $table->text('explanation')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_action')->nullable();
            $table->text('processing_note')->nullable();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id', 'status']);
            $table->index(['reporter_id', 'status']);
            $table->unique(['reporter_id', 'reportable_type', 'reportable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
