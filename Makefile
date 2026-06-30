.PHONY: start setup install migrate seed audio build dev snapshot reset

start: setup dev

setup: install migrate seed audio

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

SNAPSHOT_DIR = .snapshots
SNAPSHOT_FILE = $(SNAPSHOT_DIR)/dev-data.sqlite
AUDIO_ZIP = $(SNAPSHOT_DIR)/audio.zip

snapshot:
	@echo "Seeding fresh dev data..."
	@php artisan migrate:fresh --quiet
	@php artisan db:seed --class=DevDataSeeder --quiet
	@echo "Taking database snapshot..."
	@mkdir -p $(SNAPSHOT_DIR)
	@cp database/database.sqlite $(SNAPSHOT_FILE)
	@echo "Snapshotting audio files..."
	@cd storage && zip -qr $(abspath $(AUDIO_ZIP)) app/public/ 2>/dev/null || zip -qr $(abspath $(AUDIO_ZIP)) . 2>/dev/null; true
	@echo "Snapshot saved to $(SNAPSHOT_DIR)/"

reset:
	@if [ "$(APP_ENV)" = "production" ] || [ "$(shell php -r 'echo config("app.env");')" = "production" ]; then \
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
	@cd storage && unzip -qo $(AUDIO_ZIP) 2>/dev/null; true
	@php artisan cache:clear --quiet
	@echo "Dev data restored from snapshot!"
