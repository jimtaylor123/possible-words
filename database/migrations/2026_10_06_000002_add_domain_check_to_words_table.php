<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->string('domain_status', 20)->default('unchecked')->after('dictionary_data');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_status');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn(['domain_status', 'domain_checked_at']);
        });
    }
};
