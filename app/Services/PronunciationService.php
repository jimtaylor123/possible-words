<?php

namespace App\Services;

use App\Contracts\TtsProvider;
use App\Models\Word;

class PronunciationService
{
    public function __construct(private TtsProvider $tts) {}

    private array $onsetMap = [
        'b' => 'b', 'bl' => 'bl', 'br' => 'bɹ',
        'c' => 'k', 'ch' => 'tʃ', 'cl' => 'kl', 'cr' => 'kɹ',
        'd' => 'd', 'dr' => 'dɹ',
        'f' => 'f', 'fl' => 'fl', 'fr' => 'fɹ',
        'g' => 'g', 'gl' => 'gl', 'gr' => 'gɹ',
        'h' => 'h',
        'j' => 'dʒ',
        'k' => 'k', 'kl' => 'kl', 'kr' => 'kɹ',
        'l' => 'l',
        'm' => 'm',
        'n' => 'n',
        'p' => 'p', 'pl' => 'pl', 'pr' => 'pɹ',
        'qu' => 'kw',
        'r' => 'ɹ',
        's' => 's', 'sh' => 'ʃ', 'sk' => 'sk', 'sl' => 'sl',
        'sm' => 'sm', 'sn' => 'sn', 'sp' => 'sp', 'st' => 'st',
        'sw' => 'sw',
        't' => 't', 'tr' => 'tɹ', 'th' => 'θ',
        'v' => 'v',
        'w' => 'w',
        'y' => 'j',
        'z' => 'z', 'zh' => 'ʒ',
    ];

    private array $nucleusMap = [
        'a' => 'æ', 'e' => 'ɛ', 'i' => 'ɪ', 'o' => 'ɒ', 'u' => 'ʌ',
        'ai' => 'eɪ', 'ei' => 'eɪ', 'ie' => 'aɪ',
        'oa' => 'oʊ', 'oo' => 'uː', 'ou' => 'aʊ',
        'au' => 'ɔː', 'ar' => 'ɑː', 'or' => 'ɔː',
        'er' => 'ɜː', 'ur' => 'ɜː',
        'ay' => 'eɪ', 'ey' => 'eɪ', 'oy' => 'ɔɪ',
        'aw' => 'ɔː', 'ow' => 'aʊ',
    ];

    private array $codaMap = [
        'b' => 'b', 'c' => 'k', 'ch' => 'tʃ',
        'd' => 'd',
        'f' => 'f',
        'g' => 'g', 'gh' => '',
        'j' => 'dʒ',
        'k' => 'k',
        'l' => 'l', 'ld' => 'ld', 'lf' => 'lf',
        'lk' => 'lk', 'lm' => 'lm', 'ln' => 'ln',
        'lp' => 'lp', 'lt' => 'lt', 'lth' => 'lθ',
        'm' => 'm', 'mp' => 'mp',
        'n' => 'n', 'nd' => 'nd', 'ng' => 'ŋ',
        'nk' => 'ŋk', 'nt' => 'nt',
        'p' => 'p',
        'r' => 'ɹ', 'rd' => 'ɹd', 'rf' => 'ɹf',
        'rk' => 'ɹk', 'rm' => 'ɹm', 'rn' => 'ɹn',
        'rp' => 'ɹp', 'rt' => 'ɹt', 'rth' => 'ɹθ',
        's' => 's', 'sh' => 'ʃ', 'sk' => 'sk',
        'sp' => 'sp', 'st' => 'st',
        't' => 't', 'th' => 'θ',
        'v' => 'v',
        'x' => 'ks',
        'z' => 'z',
    ];

    public function generateIPA(Word $word): string
    {
        $phonemes = $word->phonemes;

        if (! empty($phonemes) && is_array($phonemes)) {
            return $this->ipaFromPhonemes($phonemes);
        }

        return $this->graphemeToPhoneme($word->text);
    }

    private function ipaFromPhonemes(array $phonemes): string
    {
        $parts = [];

        foreach ($phonemes as $syllable) {
            $onset = $syllable['onset'] ?? '';
            $nucleus = $syllable['nucleus'] ?? '';
            $coda = $syllable['coda'] ?? '';

            $ipaOnset = $this->onsetMap[$onset] ?? $onset;
            $ipaNucleus = $this->nucleusMap[$nucleus] ?? $nucleus;
            $ipaCoda = $this->codaMap[$coda] ?? $coda;

            $parts[] = $ipaOnset.$ipaNucleus.$ipaCoda;
        }

        return '/'.implode('.', $parts).'/';
    }

    private function graphemeToPhoneme(string $text): string
    {
        return '/'.strtolower($text).'/';
    }

    public function ensurePronunciation(Word $word): Word
    {
        $ipa = $this->generateIPA($word);
        $word->ipa = $ipa;

        if ($this->tts->isAvailable()) {
            $audioUrl = $this->tts->generateAudio($word);
            if ($audioUrl !== null) {
                $word->audio_url = $audioUrl;
            }
        }

        $word->save();

        return $word->fresh();
    }
}
