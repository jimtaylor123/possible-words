<?php

namespace App\Console\Commands;

use App\Models\Word;
use Illuminate\Console\Command;

/**
 * Withdraw words from the public site, reversibly.
 *
 * Soft deletes rather than permanently removes, so definitions, favourites and
 * votes stay attached and words:restore can undo a wrong call.
 */
class WithdrawWords extends Command
{
    protected $signature = 'words:withdraw
                            {--text=* : Withdraw specific words by text}
                            {--id=* : Withdraw specific words by id}
                            {--status=exists_as_word : Only withdraw words with this dictionary status}
                            {--chunk=100 : Words per chunk}
                            {--dry-run : List what would be withdrawn, change nothing}
                            {--confirmed : Actually withdraw (required without --dry-run)}';

    protected $description = 'Withdraw words from the public site (reversible via words:restore)';

    private const PREVIEW_LIMIT = 25;

    public function handle(): int
    {
        $texts = array_filter((array) $this->option('text'));
        $ids = array_filter((array) $this->option('id'));

        if ($texts === [] && $ids === []) {
            $status = (string) $this->option('status');
            $query = Word::query()->where('dictionary_status', $status);
            $this->line("No words given: selecting every available word with dictionary_status = {$status}.");
        } else {
            $query = Word::query()
                ->where(function ($q) use ($texts, $ids) {
                    $q->whereIn('text', $texts);

                    if ($ids !== []) {
                        $q->orWhereIn('id', $ids);
                    }
                });
        }

        // Already-withdrawn words are not candidates: re-withdrawing is a no-op
        // that would report a misleading count.
        $query->whereNull('deleted_at');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No words to withdraw.');

            return self::SUCCESS;
        }

        $this->line("{$total} word(s) match.");

        if (! $this->option('confirmed')) {
            $this->showTexts($query->orderBy('id')->limit(self::PREVIEW_LIMIT)->pluck('text')->all(), $total);

            if ($this->option('dry-run')) {
                $this->newLine();
                $this->info('Dry run: nothing was changed.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->comment('Nothing withdrawn. Re-run with --confirmed to apply, or --dry-run to preview only.');

            return self::SUCCESS;
        }

        $withdrawn = [];

        $query->chunk((int) $this->option('chunk'), function ($words) use (&$withdrawn) {
            foreach ($words as $word) {
                $word->delete();
                $withdrawn[] = $word->text;
            }
        });

        $this->info(count($withdrawn).' word(s) withdrawn.');
        $this->showTexts($withdrawn);
        $this->newLine();
        $this->comment('Undo with: php artisan words:restore --text='.implode(' --text=', array_slice($withdrawn, 0, 3)));

        return self::SUCCESS;
    }

    /**
     * Print at most PREVIEW_LIMIT texts, with a count of the remainder.
     *
     * @param  array<int, string>  $texts
     */
    private function showTexts(array $texts, ?int $total = null): void
    {
        foreach (array_slice($texts, 0, self::PREVIEW_LIMIT) as $text) {
            $this->line("  - {$text}");
        }

        $total ??= count($texts);

        if ($total > self::PREVIEW_LIMIT) {
            $remaining = $total - min($total, self::PREVIEW_LIMIT);
            $this->line('  ... and '.$remaining.' more');
        }
    }
}
