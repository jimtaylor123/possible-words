# PossibleWords

A Laravel application that generates and discovers available words that aren't in the dictionary yet, allowing users to suggest definitions and vote on them.

## Features

- **Word Discovery**: Browse generated pronounceable words that aren't real English words
- **Definition Suggestions**: Users can suggest definitions for available words
- **Voting System**: Vote up or down on definition suggestions
- **Google OAuth**: Simple authentication using Google accounts
- **Modern UI**: Built with Vue 3, Inertia.js, and Naive UI

## Setup

1. **Environment Setup**:
   - Copy `.env.example` to `.env`
   - The Google OAuth credentials are already configured
   - SQLite database is already set up

2. **Quick Start** (installs deps, migrates, seeds, and starts all dev servers):
   ```bash
   make start
   ```

   Or step by step:
   ```bash
   composer install
   npm install
   php artisan migrate
   php artisan db:seed --class=WordSeeder
   npm run build
   ```

## Usage

1. Run the dev servers:
   ```bash
   make dev
   ```
   This starts Laravel, the queue worker, Reverb (WebSockets), log viewer, and Vite concurrently.

2. Visit `http://localhost:8000`

3. Browse available words

4. Click on a word to see details and definitions

5. Login with Google to add definitions or vote

6. Use the search and filter options to find specific words

## Real-time Features (WebSockets)

The app uses [Laravel Reverb](https://reverb.laravel.com/) for real-time WebSocket features (live voting updates, definition notifications).

- Reverb starts automatically with `make dev` (or `php artisan reverb:start`)
- It runs on `ws://127.0.0.1:8080` by default (configured in `.env`)
- The queue worker must also be running to process broadcast events — also handled by `make dev`

### Troubleshooting WebSockets

If you see `WebSocket connection to 'wss://127.0.0.1:8080/...' failed`:
- Make sure your `.env` has `REVERB_SCHEME=http` and `VITE_REVERB_SCHEME="${REVERB_SCHEME}"`
- Ensure `make dev` is running (which starts Reverb and the queue)
- If serving the site over HTTPS, remove `'wss'` from `enabledTransports` in `resources/js/bootstrap.js`

## Word Generation

The app uses a simple phonotactic generator that creates pronounceable words by combining:
- Onsets (consonant clusters at the beginning)
- Nuclei (vowel sounds)
- Codas (consonant clusters at the end)

Words are filtered to exclude common English words and ensure they feel natural to pronounce.

## Future Features

- Word ownership system (like domain names)
- Payment integration for "owning" words
- More sophisticated word generation
- Better pronunciation guides
- Social features and word sharing
