<?php

use App\Contracts\TtsProvider;
use App\Services\Tts\EdgeTtsProvider;
use App\Services\Tts\OpenAiTtsProvider;

it('binds EdgeTtsProvider by default', function () {
    config(['services.tts.default' => 'edge-tts']);
    $provider = app(TtsProvider::class);
    expect($provider)->toBeInstanceOf(EdgeTtsProvider::class);
});

it('binds OpenAiTtsProvider when configured', function () {
    config(['services.tts.default' => 'openai']);
    $provider = app(TtsProvider::class);
    expect($provider)->toBeInstanceOf(OpenAiTtsProvider::class);
});
