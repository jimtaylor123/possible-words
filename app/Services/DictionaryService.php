<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DictionaryService
{
    /**
     * Errors recorded by the most recent checkWord() call, keyed by source.
     *
     * @var array<string, string>
     */
    private array $lastErrors = [];

    /**
     * Banned word lists memoised by path, so a file is read once per run.
     *
     * @var array<string, array<int, string>>
     */
    private array $bannedCache = [];

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->lastErrors;
    }

    public function checkWord(string $text): array
    {
        $this->lastErrors = [];
        $sources = [];

        if (config('dictionary.free_dictionary_enabled')) {
            $freeDictResult = $this->checkFreeDictionary($text);
            if ($freeDictResult !== null) {
                $sources['free_dictionary'] = $freeDictResult;
            }
        }

        if (config('dictionary.merriam_webster_enabled')) {
            $mwResult = $this->checkMerriamWebster($text);
            if ($mwResult !== null) {
                $sources['merriam_webster'] = $mwResult;
            }
        }

        return [
            'status' => $this->classify($sources),
            'sources' => $sources,
            'errors' => $this->lastErrors,
        ];
    }

    /**
     * GET a dictionary endpoint, retrying transient failures.
     *
     * A 404 is a real answer and is returned immediately. Connection errors and
     * unexpected statuses (the free dictionary API returns 522 fairly often) are
     * retried with a linear backoff; the last failure is re-thrown so the caller
     * records it and the word is classified as an incomplete check rather than
     * a confirmed non-word.
     */
    private function getWithRetry(string $url): Response
    {
        $attempts = max(1, (int) config('dictionary.attempts', 3));
        $sleepMs = (int) config('dictionary.retry_sleep_ms', 400);
        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout((int) config('dictionary.timeout', 10))->get($url);

                if ($response->notFound() || $attempt === $attempts) {
                    return $response;
                }

                $lastError = "unexpected HTTP {$response->status()}";
            } catch (Throwable $e) {
                $lastError = $e->getMessage();

                if ($attempt === $attempts) {
                    throw $e;
                }
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * $attempt * 1000);
            }
        }

        throw new \RuntimeException("Dictionary request failed: {$lastError}");
    }

    public function checkFreeDictionary(string $text): ?array
    {
        $url = 'https://api.dictionaryapi.dev/api/v2/entries/en/'.urlencode(strtolower($text));

        try {
            $response = $this->getWithRetry($url);

            if ($response->notFound()) {
                return ['found' => false];
            }

            if ($response->successful()) {
                $data = $response->json();
                $meanings = [];

                foreach ($data as $entry) {
                    foreach ($entry['meanings'] ?? [] as $meaning) {
                        $meanings[] = [
                            'partOfSpeech' => $meaning['partOfSpeech'],
                            'definitions' => array_map(
                                fn ($d) => $d['definition'],
                                array_slice($meaning['definitions'], 0, 3)
                            ),
                        ];
                    }
                }

                return [
                    'found' => true,
                    'meanings' => $meanings,
                ];
            }

            $this->recordError('free_dictionary', $text, "unexpected HTTP {$response->status()}");
        } catch (Throwable $e) {
            $this->recordError('free_dictionary', $text, $e->getMessage());
        }

        return null;
    }

    public function checkMerriamWebster(string $text): ?array
    {
        $apiKey = config('dictionary.merriam_webster_key');

        if (empty($apiKey)) {
            return null;
        }

        $url = 'https://www.dictionaryapi.com/api/v3/references/collegiate/json/'.urlencode(strtolower($text)).'?key='.$apiKey;

        try {
            $response = $this->getWithRetry($url);

            if ($response->successful()) {
                $data = $response->json();

                if (empty($data)) {
                    return ['found' => false];
                }

                // M-W returns suggestions as strings if the word is not found
                if (isset($data[0]) && is_string($data[0])) {
                    return ['found' => false, 'suggestions' => $data];
                }

                return ['found' => true];
            }

            $this->recordError('merriam_webster', $text, "unexpected HTTP {$response->status()}");
        } catch (Throwable $e) {
            $this->recordError('merriam_webster', $text, $e->getMessage());
        }

        return null;
    }

    /**
     * Record a failed lookup so the caller can report why a check was inconclusive.
     */
    private function recordError(string $source, string $text, string $message): void
    {
        $this->lastErrors[$source] = $message;

        Log::warning(ucfirst(str_replace('_', ' ', $source))." check failed for '{$text}': {$message}");
    }

    public function classify(array $sources): string
    {
        // Found in Merriam-Webster → definitely a real word
        if (isset($sources['merriam_webster']['found']) && $sources['merriam_webster']['found'] === true) {
            return 'exists_as_word';
        }

        if (isset($sources['free_dictionary'])) {
            $fd = $sources['free_dictionary'];

            if ($fd['found'] === false) {
                return 'not_found';
            }

            if ($fd['found'] === true && ! empty($fd['meanings'])) {
                $allProperNoun = true;

                foreach ($fd['meanings'] as $meaning) {
                    $pos = $meaning['partOfSpeech'];
                    if (! in_array($pos, ['proper noun', 'proper_noun', 'proper noun (given name)', 'proper noun (surname)'])) {
                        $allProperNoun = false;
                        break;
                    }
                }

                if ($allProperNoun) {
                    return 'exists_as_name';
                }

                return 'exists_as_word';
            }
        }

        if ($sources === []) {
            // Fail closed: no source returned a verdict, so we know nothing about
            // this word. Recording 'not_found' here publishes an unchecked word as
            // a confirmed-unused one — 81% of production was created this way.
            return 'check_failed';
        }

        return 'not_found';
    }

    /**
     * Load the banned words list, memoised by path.
     *
     * @return array<int, string>
     */
    public function loadBannedWords(): array
    {
        $path = config('dictionary.banned_words_path');

        if (array_key_exists($path, $this->bannedCache)) {
            return $this->bannedCache[$path];
        }

        $words = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $words = is_array($words) ? $words : [];

        if ($words === [] && config('dictionary.banned_words_required')) {
            throw new \RuntimeException(
                "Banned words list missing or empty at {$path}. Refusing to generate words "
                .'without the banned-words gate. Set BANNED_WORDS_REQUIRED=false to disable.'
            );
        }

        return $this->bannedCache[$path] = $words;
    }

    public function isInBannedList(string $text): bool
    {
        return in_array(strtolower($text), $this->loadBannedWords(), true);
    }
}
