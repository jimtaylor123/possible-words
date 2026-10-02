<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('votes_count');
        });
    }

    public function down(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
