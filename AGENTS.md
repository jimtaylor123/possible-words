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
- **Testing:** PHPUnit 11 (no frontend tests)

## Key Commands
| Command | Description |
|---------|-------------|
| `composer run dev` | Full dev environment (server + queue + logs + Vite HMR) |
| `npm run dev` | Vite dev server only |
| `npm run build` | Production frontend build |
| `composer run test` | Run all PHPUnit tests |
| `php artisan test` | Run all PHPUnit tests |

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
- **Framework:** PHPUnit 11 (no Pest)
- **Test suites:** `Unit` and `Feature`
- **Location:** `tests/Unit/` and `tests/Feature/`
- **Base class:** Feature tests extend `Tests\TestCase`, Unit tests extend `PHPUnit\Framework\TestCase`
- **Database:** Tests use SQLite `:memory:` (configured in `phpunit.xml`)
- **Naming:** PascalCase with `Test.php` suffix (e.g., `ExampleTest.php`)

## Important Notes
- Google OAuth credentials are in `.env` (live keys)
- Use `TODO.md` for tracking bugs and features
- `presidents.txt` is a personal file, unrelated to the project
