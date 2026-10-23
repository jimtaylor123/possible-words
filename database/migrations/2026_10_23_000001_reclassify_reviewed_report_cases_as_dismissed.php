<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_cases')
            ->where('status', 'reviewed')
            ->update(['status' => 'dismissed']);
    }

    public function down(): void
    {
        throw new \RuntimeException('Cannot restore reviewed report cases after they have been reclassified as dismissed.');
    }
};
