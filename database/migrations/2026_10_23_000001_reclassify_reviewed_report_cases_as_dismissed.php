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
        // The original reviewed cases cannot be identified after reclassification.
    }
};
