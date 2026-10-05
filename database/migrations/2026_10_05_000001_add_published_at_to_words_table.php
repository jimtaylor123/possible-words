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

        // The catalogue predates scheduled releases. Keep every existing visible row
        // visible when the new release gate is introduced.
        DB::table('words')
            ->whereNull('deleted_at')
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
