<?php

namespace App\Providers;

use App\Contracts\TtsProvider;
use App\Services\Tts\EdgeTtsProvider;
use App\Services\Tts\OpenAiTtsProvider;
use Illuminate\Support\ServiceProvider;

class TtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TtsProvider::class, function () {
            $provider = config('services.tts.default', 'edge-tts');

            return match ($provider) {
                'openai' => new OpenAiTtsProvider,
                default => new EdgeTtsProvider,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
