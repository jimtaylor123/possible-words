<?php

use App\Contracts\TtsProvider;
use App\Services\Tts\EdgeTtsProvider;
use App\Services\Tts\OpenAiTtsProvider;

describe('TTS provider binding', function () {
    test('Given no explicit provider, EdgeTtsProvider is bound by default', function () {
        config(['services.tts.default' => 'edge-tts']);
        $provider = app(TtsProvider::class);
        expect($provider)->toBeInstanceOf(EdgeTtsProvider::class);
    });

    test('Given the openai provider is configured, OpenAiTtsProvider is bound', function () {
        config(['services.tts.default' => 'openai']);
        $provider = app(TtsProvider::class);
        expect($provider)->toBeInstanceOf(OpenAiTtsProvider::class);
    });
});
