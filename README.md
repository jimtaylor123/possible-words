# PossibleWords

A Laravel application that generates and discovers available words that aren't in the dictionary yet, allowing users to suggest definitions and vote on them.

## Features

- **Word Discovery**: Browse generated pronounceable words that aren't real English words
- **Definition Suggestions**: Users can suggest definitions for available words
- **Voting System**: Vote up or down on definition suggestions
- **Google OAuth**: Simple authentication using Google accounts
- **Modern UI**: Built with Vue 3, Inertia.js, and Naive UI

## Setup

1. **Install Dependencies**:
   ```bash
   composer install
   npm install
   ```

2. **Environment Setup**:
   - Copy `.env.example` to `.env`
   - The Google OAuth credentials are already configured
   - SQLite database is already set up

3. **Database**:
   ```bash
   php artisan migrate
   php artisan db:seed --class=WordSeeder
   ```

4. **Build Assets**:
   ```bash
   npm run build
   # or for development with hot reload:
   npm run dev
   ```

5. **Start Server**:
   ```bash
   php artisan serve
   ```

## Usage

1. Visit `http://localhost:8000`
2. Browse available words
3. Click on a word to see details and definitions
4. Login with Google to add definitions or vote
5. Use the search and filter options to find specific words

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
