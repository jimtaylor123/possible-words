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
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('text')->unique();
            $table->json('phonemes')->nullable();
            $table->integer('syllables')->default(1);
            $table->enum('status', ['available', 'owned'])->default('available');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('owned_until')->nullable();
            $table->string('slug')->unique();
            $table->timestamps();

            $table->index(['status', 'syllables']);
            $table->index('text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
