<?php

use App\Models\Word;
use App\Services\PronunciationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = new PronunciationService;
});

it('generates IPA from stored phonemes', function () {
    $word = Word::factory()->create([
        'phonemes' => [
            ['onset' => 'b', 'nucleus' => 'a', 'coda' => 't'],
        ],
    ]);

    $ipa = $this->service->generateIPA($word);

    expect($ipa)->toBe('/bæt/');
});

it('generates IPA for multi-syllable words', function () {
    $word = Word::factory()->create([
        'phonemes' => [
            ['onset' => 'h', 'nucleus' => 'a', 'coda' => ''],
            ['onset' => 'l', 'nucleus' => 'ow', 'coda' => ''],
        ],
    ]);

    $ipa = $this->service->generateIPA($word);

    expect($ipa)->toBe('/hæ.laʊ/');
});

it('falls back to grapheme when no phonemes stored', function () {
    $word = Word::factory()->create([
        'phonemes' => null,
        'text' => 'testword',
    ]);

    $ipa = $this->service->generateIPA($word);

    expect($ipa)->toBe('/testword/');
});

it('generates audio via OpenAI TTS', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');

    Http::fake([
        'api.openai.com/*' => Http::response('fake-mp3-content', 200),
    ]);

    $word = Word::factory()->create([
        'text' => 'testword',
        'slug' => 'testword',
    ]);

    config(['services.openai.key' => 'sk-test']);

    $url = $this->service->generateAudio($word, '/tɛst/');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/audio/speech'
            && $request['model'] === 'tts-1'
            && $request['input'] === 'testword'
            && $request['voice'] === 'alloy'
            && $request['response_format'] === 'mp3';
    });

    Storage::disk(config('filesystems.default'))->assertExists('audio/testword.mp3');
    expect($url)->not->toBeEmpty();
});

it('returns null for audio when no API key', function () {
    config(['services.openai.key' => '']);

    $word = Word::factory()->create([
        'text' => 'testword',
        'slug' => 'testword',
    ]);

    $url = $this->service->generateAudio($word, '/tɛst/');

    expect($url)->toBeNull();
});

it('ensurePronunciation generates IPA and saves', function () {
    $word = Word::factory()->create([
        'phonemes' => [
            ['onset' => 'c', 'nucleus' => 'a', 'coda' => 't'],
        ],
        'ipa' => null,
    ]);

    $result = $this->service->ensurePronunciation($word);

    expect($result->ipa)->toBe('/kæt/');
});

it('ensurePronunciation generates audio when API key is set', function () {
    Storage::fake('s3');

    Http::fake([
        'api.openai.com/*' => Http::response('fake-mp3-content', 200),
    ]);

    config(['services.openai.key' => 'sk-test']);

    $word = Word::factory()->create([
        'phonemes' => [
            ['onset' => 'd', 'nucleus' => 'o', 'coda' => 'g'],
        ],
        'ipa' => null,
        'audio_url' => null,
    ]);

    $result = $this->service->ensurePronunciation($word);

    expect($result->ipa)->toBe('/dɒg/');
    expect($result->audio_url)->not->toBeNull();
});
