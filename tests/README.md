# Testing

This project has four test suites:

| Suite | Stack | Location | Command |
|---|---|---|---|
| Unit & feature (PHP) | Pest/PHPUnit | `tests/Unit`, `tests/Feature` | `composer test` |
| Load / stress (dev) | Pest + Stressless (k6) | `tests/Load` | `composer test:load` |
| Load / stress (prod) | Pest + Stressless (k6) | `tests/LoadProd` | `PROD_LOAD_CONFIRM=1 composer test:load:prod` |
| Frontend unit | Vitest | alongside JS components | `npm run test` |
| End-to-end | Playwright | `tests/e2e` | `npm run test:e2e` |

There is also a static analysis and style check pipeline: `composer qa` runs Pint (style), PHPStan (static analysis), and the Pest suite together.

## Prerequisites

- PHP 8.2+ and Composer (`composer install`)
- Node 20+ (`npm install`)
- Playwright browsers: `npx playwright install`

## PHP tests (Pest)

The unit and feature suites use Pest with an in-memory SQLite database, so no real database setup is needed.

```bash
# Run the full PHP suite
composer test

# Run just unit tests
php artisan test --testsuite=Unit

# Run just feature tests
php artisan test --testsuite=Feature

# Run a single test file
php artisan test tests/Feature/WordBrowsingTest.php

# Filter by test name
php artisan test --filter="home page lists"
```

Feature tests use `RefreshDatabase` (or manual `migrate:fresh`) against an in-memory SQLite DB, so they are isolated from your dev data. Note: the e2e suite is different — see below.

## Load / stress tests (Pest Stressless)

The load tests hit a **real, running HTTP server** — they are not part of `composer test` (the `Unit`/`Feature` suites run against an in-memory DB). They use Pest's Stressless plugin, which drives [k6](https://k6.io/) under the hood (the binary downloads on first use). They assert on failure rate and response latency (p95) for the homepage, word detail, and about pages.

```bash
# Make sure a server with seeded data is running first, then:
composer test:load
```

The target URL defaults to `APP_URL` from your `.env` (i.e. the `make dev` server on port 8000). To point at a different server:

```bash
LOAD_BASE_URL=http://localhost:8002 composer test:load

# Or stress a single URL ad hoc, without writing a test:
./vendor/bin/pest stress http://localhost:8002/ --duration=10 --concurrency=5
```

`stress` command options: `--duration=N` (default 5s), `--concurrency=N` (default 1), and a `--get`/`--post=payload`/`--put`/`--patch`/`--delete`/`--head`/`--options` method flag.

## Production load tests (Pest Stressless)

`tests/LoadProd/` hits the **real production deployment** (Bref/Lambda + Turso) at `https://possiblewords.jimtaylor.space`. This makes real requests against your live site and consumes AWS usage, so it is deliberately separate from the dev `test:load` suite and gated behind an explicit confirmation flag:

```bash
# Runs against prod only when you opt in:
PROD_LOAD_CONFIRM=1 composer test:load:prod

# With overrides:
PROD_LOAD_CONFIRM=1 LOAD_CONCURRENCY=5 LOAD_DURATION=10 LOAD_WORD_SLUG=cleild composer test:load:prod
```

`LOAD_BASE_URL` is set by the composer script (defaulting to the prod domain). Overrides: `LOAD_CONCURRENCY` (default 5), `LOAD_DURATION` (default 10s), `LOAD_P95_MAX_MS` (default 3000 — cold-start aware), `LOAD_WORD_SLUG` (word detail slug; prod data differs from dev fixtures, so it is not hardcoded).

**Known constraint:** the AWS account's Lambda concurrency limit is **10** (see `aws lambda get-account-settings`). Requests above that ceiling get throttled and Lambda returns **503**, which the test reports as failures. Keep `LOAD_CONCURRENCY` well below 10 (default 5 passes cleanly).

## Frontend unit tests (Vitest)

```bash
# Run once
npm run test

# Watch mode
npm run test:watch
```

## End-to-end tests (Playwright)

The e2e suite boots the real Laravel app via Playwright's `webServer` (see `playwright.config.js`) and drives a real browser against it.

**Important:** unlike the Pest suite, the e2e tests run against the app served from your **real `.env`** (there is no `.env.testing`), so they use your current dev database. Make sure you have seeded data before running them:

```bash
make fresh
```

```bash
# Run the whole e2e suite
npm run test:e2e

# Run a single spec
npx playwright test tests/e2e/browse.spec.js

# Run a single test
npx playwright test --grep "search filters"

# Interactive UI mode
npm run test:e2e:ui

# With the HTML report
npx playwright test --reporter=html
```

Notes:

- The default server port is `8002` (not the dev `8000`) to avoid clashing with `make dev`.
- Playwright reuses an already-running server on `8002` when running locally (`reuseExistingServer: true`). If you have one running, make sure it points at the same code/DB.
- On CI, Playwright starts its own server and runs with `--workers=1` and 2 retries.
- Tests that touch the network (e.g. Google OAuth redirects) assert against external hosts and can be affected by local auth state.

## Static analysis and style

```bash
# Style (Pint) — check only
composer lint

# Style — auto-fix
composer lint:fix

# Static analysis (PHPStan)
composer analyse

# Everything: lint + analyse + PHP tests
composer qa

# Frontend style (ESLint)
npm run lint
```

## CI

CI for these suites is not wired up yet (see [TODO.md](../TODO.md) / GitHub issue #48).
