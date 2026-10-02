<?php

namespace App\Jobs;

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class CheckWordDictionary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 30;

    public $tries = 3;

    /**
     * Statuses this job is allowed to (re-)check. A failed lookup must stay
     * re-checkable so the next run retries it.
     *
     * @var array<int, string>
     */
    private const RECHECKABLE = [
        Word::DICTIONARY_UNCHECKED,
        Word::DICTIONARY_CHECK_FAILED,
    ];

    public function __construct(
        public Word $word
    ) {}

    public function handle(DictionaryService $service): void
    {
        if ($this->word->trashed()
            || ! in_array($this->word->dictionary_status, self::RECHECKABLE, true)) {
            return;
        }

        $result = $service->checkWord($this->word->text);

        // 'check_failed' is stored as-is: no source returned a verdict, so this
        // word is still a candidate, not a confirmed-unused word.
        $this->word->update([
            'dictionary_status' => $result['status'],
            'dictionary_checked_at' => now(),
            'dictionary_data' => $result['sources'],
        ]);

        if ($result['status'] === Word::DICTIONARY_CHECK_FAILED) {
            Log::warning("Dictionary check did not complete for '{$this->word->text}': "
                .implode('; ', $result['errors']));
        }
    }

    public function middleware(): array
    {
        return [(new RateLimited('dictionary'))->dontRelease()];
    }
}
