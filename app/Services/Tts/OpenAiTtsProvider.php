<?php

namespace App\Services\Tts;

use App\Contracts\TtsProvider;
use App\Models\Word;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OpenAiTtsProvider implements TtsProvider
{
    public function generateAudio(Word $word): ?string
    {
        $apiKey = config('services.openai.key');

        if (empty($apiKey)) {
            return null;
        }

        $voice = config('services.tts.providers.openai.voice', 'alloy');
        $model = config('services.tts.providers.openai.model', 'tts-1');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/audio/speech', [
            'model' => $model,
            'input' => $word->text,
            'voice' => $voice,
            'response_format' => 'mp3',
        ]);

        if ($response->failed()) {
            return null;
        }

        $path = 'audio/'.$word->slug.'.mp3';
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put($path, $response->body());

        return $disk->url($path);
    }

    public function isAvailable(): bool
    {
        return ! empty(config('services.openai.key'));
    }
}
