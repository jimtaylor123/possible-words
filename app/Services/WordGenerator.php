<?php

namespace App\Services;

use App\Jobs\CheckWordDictionary;
use App\Models\Word;
use Illuminate\Support\Str;

class WordGenerator
{
    private DictionaryService $dictionaryService;

    private array $onsets = [
        'b', 'bl', 'br', 'c', 'ch', 'cl', 'cr', 'd', 'dr', 'f', 'fl', 'fr',
        'g', 'gl', 'gr', 'h', 'j', 'k', 'kl', 'kr', 'l', 'm', 'n', 'p',
        'pl', 'pr', 'qu', 'r', 's', 'sh', 'sk', 'sl', 'sm', 'sn', 'sp',
        'st', 'sw', 't', 'tr', 'th', 'v', 'w', 'y', 'z', 'zh',
    ];

    private array $nuclei = [
        'a', 'e', 'i', 'o', 'u', 'ai', 'ei', 'ie', 'oa', 'oo', 'ou', 'au',
        'ar', 'or', 'er', 'ur', 'ay', 'ey', 'oy', 'aw', 'ow',
    ];

    private array $codas = [
        'b', 'c', 'ch', 'd', 'f', 'g', 'gh', 'j', 'k', 'l', 'ld', 'lf',
        'lk', 'lm', 'ln', 'lp', 'lt', 'lth', 'm', 'mp', 'n', 'nd', 'ng',
        'nk', 'nt', 'p', 'r', 'rd', 'rf', 'rk', 'rm', 'rn', 'rp', 'rt',
        'rth', 's', 'sh', 'sk', 'sp', 'st', 't', 'th', 'v', 'x', 'z',
    ];

    public function __construct(?DictionaryService $dictionaryService = null)
    {
        $this->dictionaryService = $dictionaryService ?? app(DictionaryService::class);
    }

    public function generateWords($count = 100)
    {
        $words = [];
        $attempts = 0;
        $maxAttempts = $count * 10;

        while (count($words) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $result = $this->generateWord();

            if ($result && ! in_array($result['text'], array_column($words, 'text')) && ! $this->isRealWord($result['text'])) {
                $words[] = $result;
            }
        }

        return $words;
    }

    private function generateWord()
    {
        $syllables = rand(1, 3);
        $word = '';
        $phonemes = [];

        for ($i = 0; $i < $syllables; $i++) {
            $onset = $this->onsets[array_rand($this->onsets)];
            $nucleus = $this->nuclei[array_rand($this->nuclei)];
            $coda = $i === $syllables - 1 ? $this->codas[array_rand($this->codas)] : '';

            $word .= $onset.$nucleus.$coda;
            $phonemes[] = [
                'onset' => $onset,
                'nucleus' => $nucleus,
                'coda' => $coda,
            ];
        }

        // Apply some basic orthographic rules
        $word = $this->applyOrthographicRules($word);

        if (strlen($word) >= 3 && strlen($word) <= 12) {
            return [
                'text' => $word,
                'phonemes' => $phonemes,
            ];
        }

        return null;
    }

    private function applyOrthographicRules($word)
    {
        // Basic orthographic rules
        $word = str_replace('qu', 'qu', $word);
        $word = str_replace('ii', 'i', $word);
        $word = str_replace('uu', 'u', $word);
        $word = str_replace('aa', 'a', $word);
        $word = str_replace('ee', 'e', $word);
        $word = str_replace('oo', 'o', $word);

        return $word;
    }

    private function isRealWord(string $word): bool
    {
        return $this->dictionaryService->isInBannedList($word);
    }

    public function createWords(array $words): array
    {
        $created = [];

        foreach ($words as $word) {
            $model = Word::create([
                'text' => $word['text'],
                'phonemes' => $word['phonemes'],
                'syllables' => $this->countSyllables($word['text']),
                'status' => 'available',
                'dictionary_status' => 'unchecked',
                'slug' => Str::slug($word['text']),
            ]);

            CheckWordDictionary::dispatch($model);

            $created[] = $model;
        }

        return $created;
    }

    private function countSyllables($word)
    {
        // Simple syllable counting based on vowels
        $vowels = 'aeiouy';
        $syllables = 0;
        $previousWasVowel = false;

        for ($i = 0; $i < strlen($word); $i++) {
            $isVowel = strpos($vowels, strtolower($word[$i])) !== false;
            if ($isVowel && ! $previousWasVowel) {
                $syllables++;
            }
            $previousWasVowel = $isVowel;
        }

        return max(1, $syllables);
    }
}
