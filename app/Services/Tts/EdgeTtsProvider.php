<?php

namespace App\Services\Tts;

use App\Contracts\TtsProvider;
use App\Models\Word;
use Bestmomo\LaravelEdgeTts\Facades\EdgeTts;
use Illuminate\Support\Facades\Storage;

class EdgeTtsProvider implements TtsProvider
{
    public function generateAudio(Word $word): ?string
    {
        try {
            $voice = config('services.tts.providers.edge-tts.voice', 'en-GB-SoniaNeural');
            $result = EdgeTts::synthesize($word->text, $voice);

            if (empty($result)) {
                return null;
            }

            $disk = config('filesystems.default');
            $path = 'audio/'.$word->slug.'.mp3';
            Storage::disk($disk)->put($path, $result);

            return Storage::disk($disk)->url($path);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
