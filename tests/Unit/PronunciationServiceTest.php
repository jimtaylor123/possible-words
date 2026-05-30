<?php

use App\Contracts\TtsProvider;
use App\Models\Word;
use App\Services\PronunciationService;

beforeEach(function () {
    $this->service = new PronunciationService(mock(TtsProvider::class));
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

it('generates audio via TTS provider', function () {
    $provider = mock(TtsProvider::class);
    $provider->shouldReceive('isAvailable')->andReturn(true);
    $provider->shouldReceive('generateAudio')->andReturn('http://localhost/audio/testword.mp3');

    $service = new PronunciationService($provider);

    $word = Word::factory()->create([
        'text' => 'testword',
        'slug' => 'testword',
        'phonemes' => [
            ['onset' => 't', 'nucleus' => 'e', 'coda' => 'st'],
        ],
    ]);

    $result = $service->ensurePronunciation($word);
    expect($result->ipa)->toBe('/tɛst/');
    expect($result->audio_url)->toBe('http://localhost/audio/testword.mp3');
});

it('skips audio when provider not available', function () {
    $provider = mock(TtsProvider::class);
    $provider->shouldReceive('isAvailable')->andReturn(false);
    $provider->shouldNotReceive('generateAudio');

    $service = new PronunciationService($provider);

    $word = Word::factory()->create([
        'text' => 'testword',
        'slug' => 'testword',
        'phonemes' => [
            ['onset' => 't', 'nucleus' => 'e', 'coda' => 'st'],
        ],
    ]);

    $result = $service->ensurePronunciation($word);
    expect($result->ipa)->toBe('/tɛst/');
    expect($result->audio_url)->toBeNull();
});
