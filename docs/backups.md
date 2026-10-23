# Production backup and recovery runbook

## Ownership and objective

The production operator owns backup alerts sent to **jimtayloremail@gmail.com**
through the `possiblewords-prod-backup-alarms` SNS topic. Confirm the SNS email
subscription after the first deployment. The verified recovery point objective
(RPO) is six hours: an enabled backup runs every six hours and is alarmed when
the latest verified completion manifest is older than six hours and twenty
minutes. Freshness checks run twenty minutes past the hour, after the 15-minute
backup Lambda deadline at the next run boundary.

Each run's current data version is scheduled to expire seven days after creation
in the private stack-managed backup bucket
(`possiblewords-prod-<account-id>-backups`) under `runs/`. Its resulting
noncurrent version is scheduled to expire one day later, preventing versioning
from extending the intended seven-day retention window by another seven days. S3
lifecycle actions are asynchronous, so objects can remain temporarily after
their scheduled expiration. A run is usable only when it has `completion.json`;
it contains the dump checksum, source object paths, sizes, checksums, and
restore-verification time. The web Lambda cannot read or write this bucket.

The GitHub Actions `BACKUP_RETENTION_DAYS` repository variable controls the
retention period at deployment time; it defaults to `7`. Set it only when a
different retention period is explicitly required.

The bucket name is derived once in `serverless.yml` as
`possiblewords-${sls:stage}-${aws:accountId}-backups` (which resolves to the
`possiblewords-prod-<account-id>-backups` pattern above for the production
stage). The backup Lambda receives the concrete name through the CloudFormation
`Ref` exported as `BACKUP_BUCKET`; any other environment consuming this bucket
must set `BACKUP_BUCKET` to that exact value.

The dedicated backup role has read-only list/get access to the **complete**
production S3 disk. This intentionally includes `audio/`, root-level
application objects, and future upload prefixes: backups must protect every
object Laravel can store on that disk. It has no source-bucket write access.

## Required deployment configuration

Create these GitHub Actions secrets before deploying; never put their values in
source, command lines, manifests, or logs:

- `TURSO_ORGANIZATION`
- `TURSO_PRODUCTION_DATABASE`
- `TURSO_BACKUP_API_TOKEN` — a group-scoped Turso Platform token limited to
  `read` (retrieve the production hostname), `db:create`, `db:delete`, and
  `db:mint-token` (mint a short-lived read-only SQL token) only. It is not the
  application SQL token.

The deployment downloads that pinned Linux CLI, verifies its digest, and places
it in the backup Lambda package. The SNS endpoint is intentionally declared in
`serverless.yml`; AWS will leave it pending until the operator accepts its email.

## Alarm response

`BackupLambdaErrorsAlarm` means an invocation failed. `BackupFreshnessAlarm`
means there is no valid completion manifest younger than six hours and twenty
minutes (including
when the freshness schedule is absent). Acknowledge the alarm, inspect the
backup Lambda CloudWatch logs, and run the documented acceptance drill below.
Escalate before the six-hour RPO plus verification allowance expires. Do not
disable an alarm to acknowledge it.

## Recovery drill

1. Select only a completed manifest, never a timestamp prefix alone:
   `aws s3 cp s3://<backup-bucket>/runs/<run-id>/completion.json - | jq .`
2. Download its dump to a protected local path. Create a **new non-production**
   Turso database from it using the Turso CLI and a non-production credential;
   run `PRAGMA integrity_check;` and compare each table count with the manifest.
3. Restore `objects/` only to a non-production bucket and isolated prefix, then
   calculate SHA-256 values and compare them to the manifest before exposing it.
4. Only after those checks, configure a non-production environment to use the
   restored database and object prefix.

Never use `turso db import` against production, write to the source bucket, or
change production `TURSO_DATABASE_URL` during a drill.

## Manual database restore

Use a new database name and a Turso Platform token with `db:create` and
`db:delete`; never run these commands against the production database.

```bash
aws s3 cp s3://<backup-bucket>/runs/<run-id>/database.sql ./database.sql
sqlite3 ./restore.sqlite < ./database.sql
sqlite3 ./restore.sqlite 'PRAGMA journal_mode = WAL; PRAGMA wal_checkpoint(TRUNCATE);'
mv ./restore.sqlite ./<restore-db>.db
turso db import ./<restore-db>.db --group default
turso db shell <restore-db> 'PRAGMA integrity_check;'
turso db shell <restore-db> 'SELECT COUNT(*) FROM definitions;'
turso db destroy <restore-db> --yes
```

Turso shell import does not preserve foreign-key settings between statements,
so do not pipe a dump directly into `turso db shell`. Build a WAL SQLite file,
checkpoint it, and use `db import`, then compare every restored table count with
`completion.json` before using the restored database.

To run an on-demand production backup and freshness check:

```bash
aws lambda invoke --region eu-west-1 --function-name possiblewords-prod-backup --cli-binary-format raw-in-base64-out --cli-read-timeout 0 --payload '{"cli":"backup:run"}' /tmp/possiblewords-backup-run.json
aws lambda invoke --region eu-west-1 --function-name possiblewords-prod-backup --cli-binary-format raw-in-base64-out --payload '{"cli":"backup:check-freshness"}' /tmp/possiblewords-backup-freshness.json
```

## Post-deploy acceptance

Invoke `backup:run` through the backup Lambda, verify its `completion.json`,
`BackupVerified` CloudWatch metric, temporary Turso database deletion, and
isolated restore-prefix cleanup. Invoke `backup:check-freshness`, test both
CloudWatch alarm notifications, and record the manifest timestamp, integrity and
table-count results, file checksum result, and email receipt as operational
evidence.
