<?php

use Illuminate\Database\Schema\Blueprint;
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
