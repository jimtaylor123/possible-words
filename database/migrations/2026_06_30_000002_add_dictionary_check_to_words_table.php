<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->string('dictionary_status', 20)->default('unchecked')->after('status');
            $table->timestamp('dictionary_checked_at')->nullable()->after('dictionary_status');
            $table->json('dictionary_data')->nullable()->after('dictionary_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn(['dictionary_status', 'dictionary_checked_at', 'dictionary_data']);
        });
    }
};
