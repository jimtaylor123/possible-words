<?php

namespace App\Console\Commands;

use App\Models\Word;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseWords extends Command
{
    protected $signature = 'words:release';

    protected $description = 'Release the next batch of dictionary-verified generated words';

    public function handle(): int
    {
        $batchSize = config('word-release.batch_size');
        $publishedAt = now();

        $released = DB::transaction(function () use ($batchSize, $publishedAt) {
            $ids = Word::query()
                ->where('status', 'available')
                ->whereIn('dictionary_status', Word::PUBLISHABLE_DICTIONARY_STATUSES)
                ->whereNull('published_at')
                ->whereNotNull('generated_at')
                ->orderBy('generated_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            return Word::query()
                ->whereIn('id', $ids)
                ->whereNull('published_at')
                ->update(['published_at' => $publishedAt]);
        });

        $this->info("Released {$released} of {$batchSize} word(s).");

        return self::SUCCESS;
    }
}
