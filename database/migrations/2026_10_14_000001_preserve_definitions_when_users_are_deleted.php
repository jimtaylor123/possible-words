<?php

use App\Support\SqliteTableRebuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'turso') {
            (new SqliteTableRebuilder)->rebuild(
                'definitions',
                'CREATE TABLE "__rebuild_definitions" ("id" integer primary key autoincrement not null, "word_id" integer not null, "user_id" integer, "text" text not null, "votes_count" integer not null default 0, "created_at" datetime, "updated_at" datetime, "removed_at" datetime, "part_of_speech" varchar, "origin" varchar not null default \'user\', "example_sentence" text, foreign key("word_id") references "words"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete set null)',
                'INSERT INTO "__rebuild_definitions" ("id", "word_id", "user_id", "text", "votes_count", "created_at", "updated_at", "removed_at", "part_of_speech", "origin", "example_sentence") SELECT "id", "word_id", "user_id", "text", "votes_count", "created_at", "updated_at", "removed_at", "part_of_speech", COALESCE("origin", \'user\'), "example_sentence" FROM "definitions"',
            );

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
