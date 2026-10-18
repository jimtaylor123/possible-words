<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseWords extends Command
{
    protected $signature = 'words:release';

    protected $description = 'Release the next batch of dictionary-verified generated words';

    public function handle(NotificationService $notifications): int
    {
        $batchSize = config('word-release.batch_size');
        $publishedAt = now();

        $releasedIds = DB::transaction(function () use ($batchSize, $publishedAt) {
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
                return collect();
            }

            Word::query()
                ->whereIn('id', $ids)
                ->whereNull('published_at')
                ->update(['published_at' => $publishedAt]);

            return $ids;
        });

        Word::query()->whereIn('domain_status', [Word::DOMAIN_AVAILABLE, Word::DOMAIN_TAKEN])->whereNotNull('published_at')->whereNull('notification_sent_at')->each(function (Word $word) use ($notifications): void {
            if ($notifications->notifyNewWord($word)) {
                Word::query()->whereKey($word)->whereNull('notification_sent_at')->update(['notification_sent_at' => now()]);
            }
        });

        $this->info('Released '.$releasedIds->count()." of {$batchSize} word(s).");

        return self::SUCCESS;
    }
}
