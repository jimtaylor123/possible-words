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
   php artisan db:seed
   npm run build
   ```

## Dev Data

Local dev uses a **stable set of 1000 words** committed as a fixture (`database/seeders/fixtures/words.json`) plus their audio files (`.snapshots/audio.zip`). The words and audio are never regenerated during normal development.

- **`make fresh`** — rebuilds the database (users, words from the fixture, definitions, votes, favourites) and restores the audio files. Fast, offline, no TTS. Safe to run as often as you like.
- **`make reseed-words`** — the only thing that generates **new** words and calls the TTS service for audio. Use this only when you want a fresh set of words (e.g. after a fundamental change to the word data structure). It updates the committed fixture and audio zip so subsequent `make fresh` runs reuse the new set.
- **`make start`** — restores the committed snapshot (`.snapshots/dev-data.sqlite` + `audio.zip`) and starts the dev servers; zero regeneration.

## Usage

1. Run the dev servers:
   ```bash
   make dev
   ```
   This starts Laravel, the queue worker (which dispatches Pusher broadcasts), log viewer, and Vite concurrently.

2. Visit `http://localhost:8000`

3. Browse available words

4. Click on a word to see details and definitions

5. Login with Google to add definitions or vote

6. Use the search and filter options to find specific words

## Real-time Features (WebSockets)

The app uses [Pusher](https://pusher.com/) (via Laravel Echo + `pusher-js`) for real-time WebSocket features (live voting updates, new definition notifications).

- The browser connects to `wss://ws-<cluster>.pusher.com` using `VITE_PUSHER_APP_KEY` / `VITE_PUSHER_APP_CLUSTER` (baked into the frontend build).
- Events are broadcast via the `pusher` driver (`BROADCAST_CONNECTION=pusher`); the queue worker processes them (`make dev` starts `queue:listen`).
- See `docs/realtime.md` for how it works, how to verify it, and how to debug it.

### Troubleshooting WebSockets

If you don't see live updates:
- Make sure your `.env` has `BROADCAST_CONNECTION=pusher` and the `PUSHER_*`/`VITE_PUSHER_*` values set
- Ensure the queue worker is running (`make dev` starts it) — broadcasts are dispatched as queued jobs
- On production, `QUEUE_CONNECTION=sync` runs broadcasts inline, so no worker is needed

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
