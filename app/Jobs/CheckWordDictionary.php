<?php

namespace App\Jobs;

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

class CheckWordDictionary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 30;

    public $tries = 3;

    public function __construct(
        public Word $word
    ) {}

    public function handle(DictionaryService $service): void
    {
        if ($this->word->dictionary_status !== 'unchecked') {
            return;
        }

        $result = $service->checkWord($this->word->text);

        $this->word->update([
            'dictionary_status' => $result['status'],
            'dictionary_checked_at' => now(),
            'dictionary_data' => $result['sources'],
        ]);
    }

    public function middleware(): array
    {
        return [(new RateLimited('dictionary'))->dontRelease()];
    }
}
