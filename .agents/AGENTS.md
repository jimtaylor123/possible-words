# AGENTS.md — PossibleWords

## Overview
A web app that generates pronounceable made-up words using phonotactic rules (onsets + nuclei + codas). Users can browse words, suggest definitions, and vote on them. Built with Laravel + Inertia.js + Vue 3.

## Tech Stack
- **Backend:** Laravel 12 (PHP 8.2+)
- **Frontend:** Vue 3 Composition API (`<script setup>`), Inertia.js 2
- **CSS:** Tailwind CSS 4
- **UI Library:** Naive UI
- **Build Tool:** Vite 7 (with `laravel-vite-plugin` + `@vitejs/plugin-vue` + `@tailwindcss/vite`)
- **Auth:** Laravel Socialite (Google OAuth only)
- **Database:** SQLite

## Pre-review / CI Checklist

Before submitting any PR or requesting review, ALL of the following must pass:

### 1. PHP — Static Analysis (Larastan)
```bash
composer run analyse
```
Runs `phpstan analyse` on `app/` and `config/` directories at level 1. All errors must be fixed (no baseline, no `@phpstan-ignore` comments).

### 2. PHP — Linting (Laravel Pint)
```bash
composer run lint
```
Runs `pint --test` to check for style issues. Fails if any violations exist. Auto-fix with:
```bash
composer run lint:fix
```

### 3. PHP — Tests (Pest)
```bash
composer run test
```
Runs all Pest tests (`tests/Unit/` and `tests/Feature/`). Database is SQLite `:memory:` with `RefreshDatabase` trait. All tests must pass.

### 4. JavaScript — Linting (ESLint)
```bash
npm run lint
```
Runs ESLint on `resources/js/` with 0 warnings allowed. Auto-fix with:
```bash
npm run lint:fix
```

### 5. JavaScript — Unit Tests (Vitest)
```bash
npm run test
```
Runs Vitest on `resources/js/**/*.{test,spec}.{js,ts}` with happy-dom environment. All tests must pass.

### 6. E2E — Playwright Tests
```bash
npm run test:e2e
```
Runs Playwright tests from `tests/e2e/` against a local Laravel dev server. All tests must pass. For features, add new Playwright tests as appropriate. Bugfixes should ensure existing tests pass; add new tests if the bug isn't covered and merits continuous testing.

### Quick all-in-one
```bash
composer run qa
```
Runs lint + analyse + test for PHP in sequence.

## Key Commands
| Command | Description |
|---------|-------------|
| `composer run dev` | Full dev environment (server + queue + logs + Vite HMR) |
| `npm run dev` | Vite dev server only |
| `npm run build` | Production frontend build |
| `composer run test` | Run all Pest tests |
| `composer run lint` | Check PHP style with Pint |
| `composer run lint:fix` | Auto-fix PHP style issues |
| `composer run analyse` | Run Larastan (PHPStan) static analysis |
| `composer run qa` | Run lint + analyse + test (PHP) |
| `npm run lint` | Check JS style with ESLint |
| `npm run lint:fix` | Auto-fix JS style issues |
| `npm run test` | Run Vitest JS unit tests |
| `npm run test:e2e` | Run Playwright E2E tests |
| `php artisan serve` | Start the Laravel dev server (default port 8000) |
| `php artisan serve --port=8080` | Start the Laravel dev server on a specific port |

## Coding Conventions

### JavaScript / Vue
- Use `<script setup>` Composition API for all `.vue` files
- PascalCase for `.vue` files (e.g., `Home.vue`, `GoogleSignInButton.vue`)
- Place page components in `resources/js/Pages/` with subdirectories for nested routes (e.g., `Words/Index.vue`)
- Place shared components in `resources/js/Components/`
- Use `@` import alias for `resources/js/` (e.g., `@/Components/Layout.vue`)
- Use `route()` helper (via Ziggy) for URL generation
- Use `router` from `@inertiajs/vue3` for Inertia visits
- Use `ref`, `computed`, `defineProps` from Vue — no Pinia/Vuex, data flows through Inertia page props
- Import Naive UI components individually and register globally in `resources/js/app.js`
- Style with Tailwind utility classes only — no custom CSS files beyond `app.css`
- No TypeScript

### PHP / Laravel
- Controller methods: `index`, `show`, `storeDefinition`, `voteDefinition` pattern (descriptive names)
- Models: singular PascalCase, explicit `$fillable` and `$casts` arrays
- Route key binding: `Word` model uses `slug` (`getRouteKeyName()`)
- Validation: inline `$request->validate()` in controllers (no Form Requests currently)
- Authorization: inline `Auth::id()` / `Auth::check()` checks (no dedicated policies/gates)
- Seeders: separate files per concern, called from `DatabaseSeeder`
- Migrations: timestamp-prefixed filenames, up/down methods
- No dedicated repository layer — Eloquent used directly in controllers or services

### Routes
All routes defined in `routes/web.php`:
- Public routes: home, word listing, word show
- Auth routes: Google OAuth redirect/callback, logout
- Protected routes (auth middleware): store definition, vote definition

## Testing
- **PHP Framework:** Pest (on top of PHPUnit 11)
- **PHP Test suites:** Unit and Feature
- **PHP Locations:** `tests/Unit/` and `tests/Feature/`
- **Base class:** Feature tests use `Tests\TestCase` with `RefreshDatabase` trait
- **Database:** Tests use SQLite `:memory:` (configured in `phpunit.xml`)
- **Naming:** PascalCase with `Test.php` suffix (e.g., `ExampleTest.php`)
- **JS Framework:** Vitest 4 with happy-dom
- **JS Locations:** Co-located or in `resources/js/` with `.test.js` suffix
- **E2E:** Playwright tests in `tests/e2e/` with `.spec.js` suffix

## Important Notes
- Google OAuth credentials are in `.env` (live keys)
- Tasks and bugs are tracked in GitHub Issues (TODO.md is deprecated and will be removed)
- Unless otherwise stated, GitHub issues should be processed using the workflow detailed in `.agents/workflow.yml`
