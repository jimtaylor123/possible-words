<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\PronunciationService;
use App\Services\WordGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RegenerateWords extends Command
{
    protected $signature = 'words:regenerate
                            {--count=1000 : Number of words to generate}
                            {--no-audio : Generate words and IPA only, skip TTS audio}';

    protected $description = 'Regenerate fresh words, audio, and the committed word fixture';

    public function handle(WordGenerator $generator, PronunciationService $pronunciation): int
    {
        $count = (int) $this->option('count');
        $withAudio = ! $this->option('no-audio');

        Word::query()->delete();

        $this->line("Generating {$count} words...");
        $generated = $generator->generateWords($count, checkDictionary: false);
        $created = $generator->createWords($generated, dispatchCheckJob: false);

        if ($withAudio) {
            $this->line('Generating IPA and audio (TTS)...');
            $bar = $this->output->createProgressBar(count($created));
            $bar->start();

            foreach ($created as $word) {
                try {
                    $pronunciation->ensurePronunciation($word);
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("Failed to generate pronunciation for '{$word->text}': {$e->getMessage()}");
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        } else {
            $this->line('Skipping audio (IPA only).');
        }

        $this->dumpFixture();
        $this->info('Regenerated '.count($created).' words. Run "make fresh" or restore the snapshot to use them.');

        return self::SUCCESS;
    }

    private function dumpFixture(): void
    {
        $disk = Storage::disk('public');

        $words = Word::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Word $word) => [
                'text' => $word->text,
                'phonemes' => $word->phonemes,
                'syllables' => $word->syllables,
                'ipa' => $word->ipa,
                'audio' => $word->audio_url !== null
                    ? str_replace($disk->url(''), '', $word->audio_url)
                    : null,
            ])
            ->values();

        $path = database_path('seeders/fixtures/words.json');

        file_put_contents(
            $path,
            $words->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $this->line("Updated {$path}.");
    }
}
