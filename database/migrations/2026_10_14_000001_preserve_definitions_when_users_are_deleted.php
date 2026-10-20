<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'turso') {
            DB::unprepared('PRAGMA foreign_keys = OFF');
            DB::unprepared('ALTER TABLE "definitions" RENAME TO "__restore_definitions"');
            DB::unprepared('CREATE TABLE "definitions" ("id" integer primary key autoincrement not null, "word_id" integer not null, "user_id" integer, "text" text not null, "votes_count" integer not null default 0, "created_at" datetime, "updated_at" datetime, "removed_at" datetime, "part_of_speech" varchar, "origin" varchar not null default \'user\', "example_sentence" text, foreign key("word_id") references "words"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete set null)');
            DB::unprepared('INSERT INTO "definitions" ("id", "word_id", "user_id", "text", "votes_count", "created_at", "updated_at", "removed_at", "part_of_speech", "origin", "example_sentence") SELECT "id", "word_id", "user_id", "text", "votes_count", "created_at", "updated_at", "removed_at", "part_of_speech", COALESCE("origin", \'user\'), "example_sentence" FROM "__restore_definitions"');
            DB::unprepared('DROP TABLE "__restore_definitions"');
            DB::unprepared('CREATE INDEX "definitions_word_id_votes_count_index" ON "definitions" ("word_id", "votes_count")');
            DB::unprepared('PRAGMA foreign_keys = ON');

            return;
        }

        Schema::table('definitions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
