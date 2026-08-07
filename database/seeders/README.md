# Seeders

This directory contains the seeders that populate the database, plus the committed word fixture that makes dev data stable and offline.

## Files

| File | Purpose |
|---|---|
| `DatabaseSeeder.php` | Root seeder. Only runs in the `local` environment; delegates to `DevDataSeeder`. |
| `DevDataSeeder.php` | Main dev seeder: demo users, words (from fixture), definitions, votes and favourites. |
| `WordFixtureSeeder.php` | Loads the 1000 committed words from `fixtures/words.json` (idempotent via `firstOrCreate`). |
| `fixtures/words.json` | The committed, stable word list (1000 words, 900 with audio). |

## How dev data works

The whole point of the current design is that **words and audio are NOT regenerated every time you seed**. Seeding is fast and offline — it reuses two committed artifacts:

1. **The word list** — `database/seeders/fixtures/words.json` (committed).
2. **The audio files** — `.snapshots/audio.zip` (committed), containing exactly the 900 audio files the fixture references.

So running `make fresh` (which is `php artisan migrate:fresh --seed` + `restore-audio`) or `make reset` / `make start` gives you the same 1000 words and same audio every time, with zero TTS calls and no network.

```
make fresh        → migrate:fresh --seed  (words from fixture, audio from zip)
make reset/start  → restore .snapshots/dev-data.sqlite + audio.zip
```

## The ONLY way words and audio are recreated

`make reseed-words` is the rare, destructive operation that actually generates fresh content:

1. `php artisan migrate:fresh` — wipes the database.
2. `php artisan words:regenerate` — generates new words via `WordGenerator`, generates IPA + audio via TTS (`PronunciationService`), then **rewrites `fixtures/words.json`** from the new words.
3. `make zip-audio` — rebuilds `.snapshots/audio.zip` from the new fixture.
4. `php artisan db:seed --class=DevDataSeeder` — reseeds definitions/votes/favourites around the new words.

It takes a long time (TTS for ~1000 words) and hits external services, so it is deliberately not part of the normal `make fresh` path.

The underlying command also supports:

```bash
php artisan words:regenerate --count=1000     # override word count (default 1000)
php artisan words:regenerate --no-audio       # words + IPA only, skip TTS audio
```

## Keeping the committed artifacts in sync

If you change the fixture or audio by hand, or after running `make reseed-words`, refresh the snapshots so `make start` restores everything consistently:

```bash
make snapshot    # seeds fresh, copies DB to .snapshots/dev-data.sqlite, re-zips audio
```

## Idempotency

`WordFixtureSeeder` uses `firstOrCreate(['text' => ...])`, so running it against an existing database never duplicates words. The seeder is safe to re-run; definitions, votes and favourites are inserted in bulk, so for a clean result prefer `make fresh` (full wipe) over re-running `php artisan db:seed` on an already-seeded database.
