<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->string('domain_provider', 50)->nullable()->after('domain_checked_at');
            $table->timestamp('domain_retry_at')->nullable()->after('domain_provider');
            $table->unsignedInteger('domain_attempts')->default(0)->after('domain_retry_at');
            $table->json('domain_failure')->nullable()->after('domain_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn(['domain_provider', 'domain_retry_at', 'domain_attempts', 'domain_failure']);
        });
    }
};
