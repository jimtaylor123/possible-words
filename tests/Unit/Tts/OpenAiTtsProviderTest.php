<?php

use App\Models\Word;
use App\Services\Tts\OpenAiTtsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('generates audio via OpenAI TTS', function () {
    config(['services.openai.key' => 'sk-test']);

    Http::fake([
        'api.openai.com/*' => Http::response('fake-mp3-content', 200),
    ]);

    $word = Word::factory()->create([
        'text' => 'testword',
        'slug' => 'testword',
    ]);

    $provider = new OpenAiTtsProvider;
    $result = $provider->generateAudio($word);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/audio/speech'
            && $request['model'] === 'tts-1';
    });

    Storage::disk('public')->assertExists('audio/testword.mp3');
    expect($result)->not->toBeNull();
});

it('returns null when API call fails', function () {
    Http::fake([
        'api.openai.com/*' => Http::response('Unauthorized', 401),
    ]);

    $word = Word::factory()->create(['slug' => 'testword']);

    $provider = new OpenAiTtsProvider;
    $result = $provider->generateAudio($word);

    expect($result)->toBeNull();
});

it('isAvailable returns false when no API key', function () {
    config(['services.openai.key' => '']);

    $provider = new OpenAiTtsProvider;
    expect($provider->isAvailable())->toBeFalse();
});

it('isAvailable returns true when API key is set', function () {
    config(['services.openai.key' => 'sk-test']);

    $provider = new OpenAiTtsProvider;
    expect($provider->isAvailable())->toBeTrue();
});
