.PHONY: start setup install migrate seed audio build dev snapshot reset fresh reseed-words zip-audio restore-audio test test-load

start: reset dev

setup: install migrate seed

install:
	@echo "Installing PHP dependencies..."
	@composer install --quiet
	@echo "Installing JavaScript dependencies..."
	@npm install --silent

migrate:
	@echo "Running migrations..."
	@php artisan migrate --quiet

seed:
	@echo "Seeding database..."
	@php artisan db:seed --quiet

audio:
	@echo "Generating audio for words..."
	@php artisan words:generate-pronunciation --quiet

build:
	@npm run build

dev:
	@composer dev

test:
	@composer test

test-load:
	@composer test:load

SNAPSHOT_DIR = .snapshots
SNAPSHOT_FILE = $(SNAPSHOT_DIR)/dev-data.sqlite
AUDIO_ZIP = $(SNAPSHOT_DIR)/audio.zip
FIXTURE = database/seeders/fixtures/words.json

# Rebuild .snapshots/audio.zip to contain exactly the audio files the fixture references.
zip-audio:
	@rm -f $(AUDIO_ZIP)
	@php -r '$$d = json_decode(file_get_contents("$(FIXTURE)")); foreach (array_filter(array_column($$d, "audio")) as $$p) echo "app/public/$$p\n";' > /tmp/audio-files.txt
	@cd storage && zip -qr ../$(AUDIO_ZIP) -@ < /tmp/audio-files.txt
	@rm -f /tmp/audio-files.txt
	@echo "Audio zip rebuilt from fixture."

restore-audio:
	@cd storage && unzip -qo ../$(AUDIO_ZIP) 2>/dev/null; true

# Same 1000 words + audio every time. Reuses the committed fixture + audio zip.
fresh:
	@echo "Seeding fresh dev data (words from fixture, no TTS)..."
	@php artisan migrate:fresh --seed --quiet
	@$(MAKE) --no-print-directory restore-audio
	@echo "Done."

# Rare operation: generate new words + audio (TTS), update fixture and audio zip.
reseed-words:
	@echo "Regenerating words and audio (this calls TTS)..."
	@php artisan migrate:fresh --quiet
	@php artisan words:regenerate
	@$(MAKE) --no-print-directory zip-audio
	@php artisan db:seed --class=DevDataSeeder --quiet
	@echo "Words, audio, and fixture regenerated."

snapshot:
	@echo "Seeding fresh dev data..."
	@php artisan migrate:fresh --quiet
	@php artisan db:seed --class=DevDataSeeder --quiet
	@echo "Taking database snapshot..."
	@mkdir -p $(SNAPSHOT_DIR)
	@cp database/database.sqlite $(SNAPSHOT_FILE)
	@echo "Snapshotting audio files..."
	@$(MAKE) --no-print-directory zip-audio
	@echo "Snapshot saved to $(SNAPSHOT_DIR)/"

reset:
	@if [ "$(APP_ENV)" = "production" ]; then \
		echo "ERROR: Cannot reset snapshot in production!"; \
		exit 1; \
	fi
	@if [ ! -f $(SNAPSHOT_FILE) ]; then \
		echo "No snapshot found at $(SNAPSHOT_FILE). Run 'make snapshot' first."; \
		exit 1; \
	fi
	@echo "Restoring database..."
	@cp $(SNAPSHOT_FILE) database/database.sqlite
	@echo "Restoring audio files..."
	@$(MAKE) --no-print-directory restore-audio
	@php artisan cache:clear --quiet
	@echo "Dev data restored from snapshot!"
