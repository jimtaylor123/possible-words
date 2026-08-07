<?php

use App\Contracts\TtsProvider;
use App\Models\Word;
use App\Services\PronunciationService;

beforeEach(function () {
    $this->service = new PronunciationService(mock(TtsProvider::class));
});

describe('generating IPA', function () {
    test('Given stored phonemes, IPA is generated from them', function () {
        $word = Word::factory()->create([
            'phonemes' => [
                ['onset' => 'b', 'nucleus' => 'a', 'coda' => 't'],
            ],
        ]);

        $ipa = $this->service->generateIPA($word);

        expect($ipa)->toBe('/bæt/');
    });

    test('Given a multi-syllable word, IPA is generated per syllable', function () {
        $word = Word::factory()->create([
            'phonemes' => [
                ['onset' => 'h', 'nucleus' => 'a', 'coda' => ''],
                ['onset' => 'l', 'nucleus' => 'ow', 'coda' => ''],
            ],
        ]);

        $ipa = $this->service->generateIPA($word);

        expect($ipa)->toBe('/hæ.laʊ/');
    });

    test('Given no stored phonemes, the text is used as a fallback', function () {
        $word = Word::factory()->create([
            'phonemes' => null,
            'text' => 'testword',
        ]);

        $ipa = $this->service->generateIPA($word);

        expect($ipa)->toBe('/testword/');
    });
});

describe('ensuring pronunciation', function () {
    test('Given an available provider, IPA and audio are generated', function () {
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

    test('Given an unavailable provider, IPA is set but audio is skipped', function () {
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

    test('Given a failed TTS generation, audio_url stays null', function () {
        $provider = mock(\App\Contracts\TtsProvider::class);
        $provider->shouldReceive('isAvailable')->andReturn(true);
        $provider->shouldReceive('generateAudio')->andReturn(null);

        $service = new \App\Services\PronunciationService($provider);

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
});
