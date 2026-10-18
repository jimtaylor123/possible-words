<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('kind')->default('generic')->index()->after('type');
            $table->nullableMorphs('subject');
            $table->timestamp('dismissed_at')->nullable()->index()->after('read_at');
            $table->index(['notifiable_type', 'notifiable_id', 'dismissed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'dismissed_at', 'created_at']);
            $table->dropMorphs('subject');
            $table->dropColumn(['kind', 'dismissed_at']);
        });
    }
};
