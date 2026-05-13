<?php

namespace App\Services;

use App\Models\Word;
use Illuminate\Support\Str;

class WordGenerator
{
    private $onsets = [
        'b', 'bl', 'br', 'c', 'ch', 'cl', 'cr', 'd', 'dr', 'f', 'fl', 'fr',
        'g', 'gl', 'gr', 'h', 'j', 'k', 'kl', 'kr', 'l', 'm', 'n', 'p',
        'pl', 'pr', 'qu', 'r', 's', 'sh', 'sk', 'sl', 'sm', 'sn', 'sp',
        'st', 'sw', 't', 'tr', 'th', 'v', 'w', 'y', 'z', 'zh'
    ];

    private $nuclei = [
        'a', 'e', 'i', 'o', 'u', 'ai', 'ei', 'ie', 'oa', 'oo', 'ou', 'au',
        'ar', 'or', 'er', 'ur', 'ay', 'ey', 'oy', 'aw', 'ow'
    ];

    private $codas = [
        'b', 'c', 'ch', 'd', 'f', 'g', 'gh', 'j', 'k', 'l', 'ld', 'lf',
        'lk', 'lm', 'ln', 'lp', 'lt', 'lth', 'm', 'mp', 'n', 'nd', 'ng',
        'nk', 'nt', 'p', 'r', 'rd', 'rf', 'rk', 'rm', 'rn', 'rp', 'rt',
        'rth', 's', 'sh', 'sk', 'sp', 'st', 't', 'th', 'v', 'x', 'z'
    ];

    public function generateWords($count = 100)
    {
        $words = [];
        $attempts = 0;
        $maxAttempts = $count * 10;

        while (count($words) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $word = $this->generateWord();
            
            if ($word && !in_array($word, $words) && !$this->isRealWord($word)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private function generateWord()
    {
        $syllables = rand(1, 3);
        $word = '';

        for ($i = 0; $i < $syllables; $i++) {
            $onset = $this->onsets[array_rand($this->onsets)];
            $nucleus = $this->nuclei[array_rand($this->nuclei)];
            $coda = $i === $syllables - 1 ? $this->codas[array_rand($this->codas)] : '';
            
            $word .= $onset . $nucleus . $coda;
        }

        // Apply some basic orthographic rules
        $word = $this->applyOrthographicRules($word);
        
        return strlen($word) >= 3 && strlen($word) <= 12 ? $word : null;
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

    private function isRealWord($word)
    {
        // Simple check against common English words
        $commonWords = [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 'was', 'one', 'our',
            'out', 'day', 'get', 'has', 'him', 'his', 'how', 'man', 'new', 'now', 'old', 'see', 'two', 'way',
            'who', 'boy', 'did', 'its', 'let', 'put', 'say', 'she', 'too', 'use', 'cat', 'dog', 'run', 'sun',
            'fun', 'big', 'red', 'hot', 'top', 'cup', 'hat', 'bat', 'rat', 'mat', 'sat', 'pat', 'fat', 'vat'
        ];
        
        return in_array(strtolower($word), $commonWords);
    }

    public function createWords($words)
    {
        $created = [];
        
        foreach ($words as $word) {
            $created[] = Word::create([
                'text' => $word,
                'syllables' => $this->countSyllables($word),
                'status' => 'available',
                'slug' => Str::slug($word),
            ]);
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
            if ($isVowel && !$previousWasVowel) {
                $syllables++;
            }
            $previousWasVowel = $isVowel;
        }
        
        return max(1, $syllables);
    }
}
