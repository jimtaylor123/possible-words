<?php

namespace App\Console\Commands;

use App\Models\Word;
use Illuminate\Console\Command;

/**
 * Undo a withdrawal.
 *
 * The inverse of words:withdraw: clears deleted_at, so the word returns to the
 * public list if its dictionary verdict still allows it.
 */
class RestoreWords extends Command
{
    protected $signature = 'words:restore
                            {--text=* : Restore words by text}
                            {--id=* : Restore words by id}
                            {--all : Restore every withdrawn word}
                            {--chunk=100 : Words per chunk}';

    protected $description = 'Restore words previously withdrawn by words:withdraw';

    public function handle(): int
    {
        $texts = array_filter((array) $this->option('text'));
        $ids = array_filter((array) $this->option('id'));

        if (! $this->option('all') && $texts === [] && $ids === []) {
            $withdrawn = Word::onlyTrashed()->count();

            $this->info("{$withdrawn} withdrawn word(s). Restore some with --text=..., --id=..., or all with --all.");

            return self::SUCCESS;
        }

        $query = Word::onlyTrashed();

        if (! $this->option('all')) {
            $query->where(function ($q) use ($texts, $ids) {
                $q->whereIn('text', $texts);

                if ($ids !== []) {
                    $q->orWhereIn('id', $ids);
                }
            });
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to restore.');

            return self::SUCCESS;
        }

        $restored = [];

        $query->chunk((int) $this->option('chunk'), function ($words) use (&$restored) {
            foreach ($words as $word) {
                $word->restore();
                $restored[] = $word->text;
            }
        });

        $this->info(count($restored).' word(s) restored.');

        foreach (array_slice($restored, 0, 25) as $text) {
            $this->line("  - {$text}");
        }

        if (count($restored) > 25) {
            $this->line('  ... and '.(count($restored) - 25).' more');
        }

        return self::SUCCESS;
    }
}
