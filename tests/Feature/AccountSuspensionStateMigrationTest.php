<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;

test('recovers from a partially applied account suspension migration', function () {
    Schema::drop('account_suspension_actions');

    Schema::table('users', function (Blueprint $table) {
        $table->dropForeign(['suspended_by_user_id']);
        $table->dropColumn('suspension_reason');
    });

    Schema::create('account_suspension_actions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('action');
        $table->text('reason')->nullable();
        $table->timestamps();
    });

    $migration = require database_path('migrations/2026_10_23_000002_add_account_suspension_state_to_users.php');
    $migration->up();
    $migration->up();

    $suspendedByUserForeignKeys = array_values(array_filter(
        Schema::getForeignKeys('users'),
        fn (array $foreignKey): bool => $foreignKey['columns'] === ['suspended_by_user_id'],
    ));

    expect(Schema::hasColumn('users', 'last_active_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'suspended_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'suspended_by_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'suspension_reason'))->toBeTrue()
        ->and(Schema::hasIndex('users', ['last_active_at']))->toBeTrue()
        ->and(Schema::hasIndex('users', ['suspended_at']))->toBeTrue()
        ->and(Schema::hasTable('account_suspension_actions'))->toBeTrue()
        ->and(Schema::hasIndex('account_suspension_actions', 'account_suspension_actions_user_id_created_at_id_index'))->toBeTrue()
        ->and(Schema::hasIndex('account_suspension_actions', 'account_suspension_actions_admin_user_id_index'))->toBeTrue()
        ->and(count($suspendedByUserForeignKeys))->toBe(1)
        ->and($suspendedByUserForeignKeys[0]['foreign_table'])->toBe('users')
        ->and($suspendedByUserForeignKeys[0]['foreign_columns'])->toBe(['id'])
        ->and($suspendedByUserForeignKeys[0]['on_delete'])->toBe('set null');
});

test('uses Turso-compatible metadata to recover and retry partial users and actions table states', function () {
    Schema::drop('account_suspension_actions');

    Schema::table('users', function (Blueprint $table) {
        $table->dropForeign(['suspended_by_user_id']);
        $table->dropColumn('suspension_reason');
    });

    Schema::create('account_suspension_actions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('action');
        $table->text('reason')->nullable();
        $table->timestamps();
    });

    $originalBuilder = Schema::getFacadeRoot();
    $connection = new class (Schema::getConnection()->getPdo(), ':memory:', '', ['driver' => 'turso']) extends SQLiteConnection
    {
        /** @var list<string> */
        public array $queries = [];

        /** @var list<string> */
        public array $statements = [];

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            $this->queries[] = $query;

            if (str_contains(strtolower($query), 'pragma_table_xinfo') || str_contains(strtolower($query), 'pragma_index_list')) {
                throw new RuntimeException("Turso does not support table-valued pragma queries: {$query}");
            }

            return parent::select($query, $bindings, $useReadPdo);
        }

        public function statement($query, $bindings = [])
        {
            $this->statements[] = $query;

            return parent::statement($query, $bindings);
        }
    };
    Schema::swap($connection->getSchemaBuilder());

    try {
        $migration = require database_path('migrations/2026_10_23_000002_add_account_suspension_state_to_users.php');
        $migration->up();
        $migration->up();

        Schema::swap($originalBuilder);

        expect($connection->queries)->toContain("PRAGMA table_info('users')")
            ->toContain("PRAGMA table_info('account_suspension_actions')")
            ->toContain("SELECT 1 FROM sqlite_schema WHERE type = 'index' AND tbl_name = ? AND name = ?")
            ->and(collect($connection->statements)->contains(
                fn (string $statement): bool => str_contains(strtolower($statement), 'create index')
                    && (str_contains($statement, 'users_last_active_at_index')
                        || str_contains($statement, 'users_suspended_at_index')),
            ))->toBeFalse()
            ->and(Schema::hasColumn('users', 'last_active_at'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'suspended_at'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'suspended_by_user_id'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'suspension_reason'))->toBeTrue()
            ->and(Schema::hasIndex('users', ['last_active_at']))->toBeTrue()
            ->and(Schema::hasIndex('users', ['suspended_at']))->toBeTrue()
            ->and(Schema::hasTable('account_suspension_actions'))->toBeTrue()
            ->and(Schema::hasIndex('account_suspension_actions', 'account_suspension_actions_user_id_created_at_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('account_suspension_actions', 'account_suspension_actions_admin_user_id_index'))->toBeTrue();
    } finally {
        Schema::swap($originalBuilder);
    }
});
