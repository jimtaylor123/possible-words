<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('email_sent_at')->nullable()->index();
            $table->timestamp('email_claimed_at')->nullable()->index();
            $table->uuid('email_claim_token')->nullable()->index();
            $table->string('dedupe_key')->nullable();
            $table->timestamps();
            $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('notification_email_mode')->default('daily');
            $table->string('admin_report_email_mode')->default('daily');
        });
        Schema::table('words', function (Blueprint $table) {
            $table->timestamp('notification_sent_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('words', fn (Blueprint $table) => $table->dropColumn('notification_sent_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['notification_email_mode', 'admin_report_email_mode']));
        Schema::dropIfExists('notifications');
    }
};
