<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DictionaryService
{
    public function checkWord(string $text): array
    {
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

        $status = $this->classify($sources);

        return [
            'status' => $status,
            'sources' => $sources,
        ];
    }

    public function checkFreeDictionary(string $text): ?array
    {
        $url = 'https://api.dictionaryapi.dev/api/v2/entries/en/'.urlencode(strtolower($text));

        try {
            $response = Http::timeout(10)->get($url);

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
        } catch (\Exception $e) {
            Log::warning("FreeDictionaryAPI check failed for '{$text}': {$e->getMessage()}");
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
            $response = Http::timeout(10)->get($url);

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
        } catch (\Exception $e) {
            Log::warning("Merriam-Webster check failed for '{$text}': {$e->getMessage()}");
        }

        return null;
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

        return 'not_found';
    }

    public function loadBannedWords(): array
    {
        $path = config('dictionary.banned_words_path');

        if (! file_exists($path)) {
            return [];
        }

        $words = json_decode(file_get_contents($path), true);

        return is_array($words) ? $words : [];
    }

    public function isInBannedList(string $text): bool
    {
        return in_array(strtolower($text), $this->loadBannedWords(), true);
    }
}
