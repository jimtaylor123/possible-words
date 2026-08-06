<?php

namespace Database\Seeders;

use App\Models\Word;
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

        $disk = Storage::disk('public');
        $created = 0;

        foreach ($words as $word) {
            $audioUrl = null;

            if (! empty($word['audio'])) {
                $audioUrl = $disk->url($word['audio']);
            }

            Word::firstOrCreate(['text' => $word['text']], [
                'phonemes' => $word['phonemes'] ?? null,
                'syllables' => $word['syllables'] ?? 1,
                'ipa' => $word['ipa'] ?? null,
                'audio_url' => $audioUrl,
                'status' => 'available',
                'dictionary_status' => 'unchecked',
            ]);

            $created++;
        }

        $this->command->info("Loaded {$created} words from fixture.");
    }
}
