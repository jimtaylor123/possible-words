<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION_USER_CREATED_ID_INDEX = 'account_suspension_actions_user_id_created_at_id_index';

    private const ACTION_ADMIN_USER_INDEX = 'account_suspension_actions_admin_user_id_index';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'last_active_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_active_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'suspended_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('suspended_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'suspended_by_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('suspended_by_user_id')->nullable();
            });
        }

        if (! $this->usesTursoConnection() && ! $this->hasSuspendedByUserForeignKey()) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('suspended_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('users', 'suspension_reason')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('suspension_reason')->nullable();
            });
        }

        if (! Schema::hasIndex('users', ['last_active_at'])) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('last_active_at');
            });
        }

        if (! Schema::hasIndex('users', ['suspended_at'])) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('suspended_at');
            });
        }

        if (! Schema::hasTable('account_suspension_actions')) {
            Schema::create('account_suspension_actions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action');
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at', 'id'], self::ACTION_USER_CREATED_ID_INDEX);
                $table->index('admin_user_id', self::ACTION_ADMIN_USER_INDEX);
            });
        } elseif (! Schema::hasColumns('account_suspension_actions', [
            'id',
            'user_id',
            'admin_user_id',
            'action',
            'reason',
            'created_at',
            'updated_at',
        ])) {
            throw new RuntimeException('The existing account_suspension_actions table does not match the account suspension migration schema.');
        }

        if (! Schema::hasIndex('account_suspension_actions', self::ACTION_USER_CREATED_ID_INDEX)) {
            Schema::table('account_suspension_actions', function (Blueprint $table) {
                $table->index(['user_id', 'created_at', 'id'], self::ACTION_USER_CREATED_ID_INDEX);
            });
        }

        if (! Schema::hasIndex('account_suspension_actions', self::ACTION_ADMIN_USER_INDEX)) {
            Schema::table('account_suspension_actions', function (Blueprint $table) {
                $table->index('admin_user_id', self::ACTION_ADMIN_USER_INDEX);
            });
        }
    }

    private function usesTursoConnection(): bool
    {
        // turso-laravel registers its libSQL connections with Laravel's "turso"
        // driver, even when its db_url begins with the libsql: scheme.
        return Schema::getConnection()->getConfig('driver') === 'turso';
    }

    private function hasSuspendedByUserForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('users'))->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['suspended_by_user_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
                && $foreignKey['on_delete'] === 'set null',
        );
    }

    public function down(): void
    {
        throw new RuntimeException('This forward-only recovery migration cannot be rolled back safely.');
    }
};
