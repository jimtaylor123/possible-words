<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('generated_at')->index();
        });

        // The catalogue predates scheduled releases. Preserve only rows that were
        // public under the former eligibility rules; pending inventory must wait for
        // words:release even if it later receives a publishable dictionary verdict.
        DB::table('words')
            ->whereNull('deleted_at')
            ->where('status', 'available')
            ->whereIn('dictionary_status', ['not_found', 'exists_as_name'])
            ->update(['published_at' => DB::raw('COALESCE(generated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });
    }
};
