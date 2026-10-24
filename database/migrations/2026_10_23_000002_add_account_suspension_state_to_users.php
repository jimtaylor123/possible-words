<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamp('suspended_at')->nullable()->index();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_reason')->nullable();
        });

        Schema::create('account_suspension_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id']);
            $table->index('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_suspension_actions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by_user_id');
            $table->dropIndex(['last_active_at']);
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['last_active_at', 'suspended_at', 'suspension_reason']);
        });
    }
};
