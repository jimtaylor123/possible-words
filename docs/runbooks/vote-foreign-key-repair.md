# Repairing the definition-vote foreign key (#220)

The October 14 Turso definitions migration renamed its parent table before
rebuilding it. SQLite rewrote `votes.definition_id` to reference
`__restore_definitions`, which was then dropped. New likes fail at insertion.

The November 5 repair migration rebuilds only an affected `votes` table and
changes that reference back to `definitions`. Healthy databases are a no-op.
It preserves the stored table definition, rows, indexes, triggers, and ID sequence.
A foreign-key check runs before commit; failures roll back the rebuild. The
original definitions migration now creates its replacement before dropping the
old table, preserving incoming references on fresh installations.

## Release and verification

Use the normal deployment workflow after human acceptance. It updates the Artisan
Lambda before running `migrate --force`, so the migration runner includes both the
new repair and the Turso driver fix. Confirm the latest validated database backup
is available before the production migration.

Before and after deployment, inspect the database using read-only queries:

```sql
SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'votes';
PRAGMA foreign_key_list('votes');
SELECT COUNT(*) FROM votes;
SELECT COUNT(*) FROM definitions;
PRAGMA foreign_key_check;
SELECT name FROM sqlite_schema WHERE sql LIKE '%__restore_%';
```

After deployment, `votes.definition_id` must reference `definitions(id)` with
`ON DELETE CASCADE`, the foreign-key check must return no rows, and no schema
objects should reference `__restore_definitions`. Counts should remain stable
apart from concurrent legitimate user activity. Sign in, like a definition,
refresh to check persistence, then unlike and refresh again. No 500 should appear.

If the migration fails, inspect its exception and schema before retrying. The
rebuild transaction preserves the original table on failure; do not disable
foreign keys globally or delete orphaned rows to force completion. The repair's
`down()` deliberately leaves the valid reference in place.

## Regression evidence

Run `composer run qa` and the integration command in `tests/README.md`. The
integration suite uses a disposable local Turso server through the real HTTP
driver. It covers existing votes, deleted high IDs, uniqueness, cascades,
idempotence, orphan rejection, and rollback after SQL errors. The driver must
retain the response's replacement baton even when a SQL error is thrown, so a
subsequent `ROLLBACK` uses the current connection token.
