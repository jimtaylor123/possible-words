<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            // NULL remains the contributor default. SQL unique indexes permit multiple
            // NULLs, while allowing just one named generated definition per word.
            $table->string('origin')->nullable()->after('part_of_speech');
            $table->unique(['word_id', 'origin']);
        });

        Schema::table('words', function (Blueprint $table) {
            $table->string('ai_definition_status')->default('pending')->after('generated_at');
            $table->timestamp('ai_definition_attempted_at')->nullable()->after('ai_definition_status');
            $table->string('ai_definition_error', 255)->nullable()->after('ai_definition_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn(['ai_definition_status', 'ai_definition_attempted_at', 'ai_definition_error']);
        });

        Schema::table('definitions', function (Blueprint $table) {
            $table->dropUnique(['word_id', 'origin']);
            $table->dropColumn('origin');
        });
    }
};
