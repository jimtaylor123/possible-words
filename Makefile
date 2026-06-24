.PHONY: start setup install migrate seed audio build dev

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
	@php artisan db:seed --class=WordSeeder --quiet

audio:
	@echo "Generating audio for words..."
	@php artisan words:generate-pronunciation --quiet

build:
	@npm run build

dev:
	@composer dev
