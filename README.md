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

## Testing

See [tests/README.md](tests/README.md) for how to run the PHP (Pest), frontend (Vitest), and end-to-end (Playwright) test suites.

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

## Production AI definition backfill

Definition generation is intentionally manual; there is no scheduled backfill. Before using it,
ensure the production repository's `GEMINI_API_KEY` GitHub Actions secret is set and deploy the
configuration so it is passed to the Artisan Lambda.

Run one bounded batch at a time. `--limit` is required, must be a positive integer, and is capped
at 10. Eligible words are selected in ascending ID order from pending generation attempts and
never include words that already have an AI definition.

```bash
aws lambda invoke \
  --region eu-west-1 \
  --function-name possiblewords-prod-artisan \
  --cli-binary-format raw-in-base64-out \
  --cli-read-timeout 0 \
  --payload '{"cli":"words:generate-definitions --limit=10"}' \
  /tmp/possiblewords-definition-backfill.json
cat /tmp/possiblewords-definition-backfill.json
```

The command reports how many definitions were selected. Production uses the synchronous queue,
so each selected generation runs within that Lambda invocation; inspect the response and CloudWatch
logs for failures before starting another batch. Do not run concurrent batches or increase the cap.

Failed attempts are excluded by default. After investigating a failure, retry a bounded combined
set of pending and failed attempts (also in ascending ID order) with:

```bash
aws lambda invoke \
  --region eu-west-1 \
  --function-name possiblewords-prod-artisan \
  --cli-binary-format raw-in-base64-out \
  --cli-read-timeout 0 \
  --payload '{"cli":"words:generate-definitions --limit=10 --retry-failed"}' \
  /tmp/possiblewords-definition-retry.json
cat /tmp/possiblewords-definition-retry.json
```

Repeat only after checking failures; `--retry-failed` does not bypass the 10-word cap and still
excludes words that already received an AI definition.

## .com domain availability checks

Every word's `.com` availability is looked up against Verisign's free public RDAP endpoint — there
is no API key anywhere in this feature. `words:check-domains` checks words whose verdict is
missing or more than a day old, and the results drive the "free .com" filter on browse, the
suggestions endpoint and the word detail page.

The command is scheduled every eleven minutes (locally by the Laravel scheduler; in production by
the EventBridge event in `serverless.yml`). A database lease means only one invocation runs at a
time, and the command always exits successfully so a flaky third-party API never fails the
scheduled event — a failed lookup is recorded as `check_failed` and the word shows no link.

All settings live in `config/domain.php` and read these environment variables, with these
defaults:

| Variable | Default | Purpose |
| --- | --- | --- |
| `DOMAIN_CHECK_ENABLED` | `true` | Kill switch; `false` skips the run entirely |
| `DOMAIN_RDAP_URL` | `https://rdap.verisign.com/com/v1/domain` | Base URL for .com lookups |
| `DOMAIN_TIMEOUT` | `10` | Seconds to wait for each RDAP response |
| `DOMAIN_ATTEMPTS` | `3` | Attempts per lookup before recording `check_failed` |
| `DOMAIN_RETRY_SLEEP_MS` | `400` | Linear backoff between attempts |
| `DOMAIN_THROTTLE` | `4` | Max RDAP requests per second during a run |
| `DOMAIN_RUN_LOCK_TTL_SECONDS` | `720` | Lease lifetime; must cover a full Lambda run |

`.env.example` sets the basics (`DOMAIN_CHECK_ENABLED`, `DOMAIN_THROTTLE`); `serverless.yml` pins
the resilience and rate values for production, and the schedule's `--chunk=8 --limit=8` bounds
each invocation so the worst case fits the Lambda timeout. Run a bounded batch by hand:

```bash
php artisan words:check-domains --chunk=8 --limit=8
```

`--force` re-checks recently checked words, `--limit` caps how many words one run touches, and
`--throttle` overrides `DOMAIN_THROTTLE` for that run.

## Future Features

- Word ownership system (like domain names)
- Payment integration for "owning" words
- More sophisticated word generation
- Better pronunciation guides
- Social features and word sharing
