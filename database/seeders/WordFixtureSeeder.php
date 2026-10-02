<?php

namespace Database\Seeders;

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class WordFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/fixtures/words.json');

        if (! file_exists($path)) {
            $this->command->error('Word fixture not found. Run "make reseed-words" to generate it.');

            return;
        }

        $words = json_decode(file_get_contents($path), true);

        if (! is_array($words)) {
            $this->command->error('Word fixture is invalid JSON.');

            return;
        }

        $dictionary = app(DictionaryService::class);
        $disk = Storage::disk('public');
        $created = 0;
        $skipped = 0;

        foreach ($words as $word) {
            // The banned list is the same gate words:generate runs. Fixture data
            // should not smuggle a known real word back onto the site.
            if ($dictionary->isInBannedList($word['text'])) {
                $skipped++;

                continue;
            }

            $audioUrl = null;

            if (! empty($word['audio'])) {
                $audioUrl = $disk->url($word['audio']);
            }

            // Seeded as not_found with a recorded verdict, not as unchecked:
            // 'unchecked' is deliberately not publishable, so fixture words
            // would be invisible on the browse page and in suggestions.
            Word::firstOrCreate(['text' => $word['text']], [
                'phonemes' => $word['phonemes'] ?? null,
                'syllables' => $word['syllables'] ?? 1,
                'ipa' => $word['ipa'] ?? null,
                'audio_url' => $audioUrl,
                'status' => 'available',
                'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
                'dictionary_checked_at' => now(),
                'dictionary_data' => ['free_dictionary' => ['found' => false]],
            ]);

            $created++;
        }

        $this->command->info("Loaded {$created} words from fixture.");

        if ($skipped > 0) {
            $this->command->warn("Skipped {$skipped} banned word(s).");
        }
    }
}
