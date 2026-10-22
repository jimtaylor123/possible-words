<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_rate_limits', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamp('next_available_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_rate_limits');
    }
};
