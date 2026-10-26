<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION_USER_CREATED_ID_INDEX = 'account_suspension_actions_user_id_created_at_id_index';

    private const ACTION_ADMIN_USER_INDEX = 'account_suspension_actions_admin_user_id_index';

    private const USER_COLUMNS = [
        'last_active_at',
        'suspended_at',
        'suspended_by_user_id',
        'suspension_reason',
    ];

    public function up(): void
    {
        $existingUserColumns = $this->existingUserColumns();

        if (! in_array('last_active_at', $existingUserColumns, true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_active_at')->nullable();
            });
        }

        if (! in_array('suspended_at', $existingUserColumns, true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('suspended_at')->nullable();
            });
        }

        if (! in_array('suspended_by_user_id', $existingUserColumns, true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('suspended_by_user_id')->nullable();
            });
        }

        if (! $this->usesTursoConnection() && ! $this->hasSuspendedByUserForeignKey()) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('suspended_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! in_array('suspension_reason', $existingUserColumns, true)) {
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

    /**
     * @return list<string>
     */
    private function existingUserColumns(): array
    {
        if (! $this->usesTursoConnection()) {
            return array_values(array_filter(
                self::USER_COLUMNS,
                fn (string $column): bool => Schema::hasColumn('users', $column),
            ));
        }

        return array_values(array_intersect(
            self::USER_COLUMNS,
            array_map(
                fn (object $column): string => $column->name,
                Schema::getConnection()->select('PRAGMA table_info(users)'),
            ),
        ));
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
