<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('words')->whereNotNull('published_at')->whereNull('notification_sent_at')->update(['notification_sent_at' => now()]);
    }

    public function down(): void
    {
        // Historical notification state must not be reset on rollback.
    }
};
