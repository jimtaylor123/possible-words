<?php

use App\Contracts\TtsProvider;
use App\Models\Word;
use App\Services\PronunciationService;
use App\Services\TtsRateLimiter;

beforeEach(function () {
    $this->service = new PronunciationService(mock(TtsProvider::class), mock(TtsRateLimiter::class));
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

        $rateLimiter = mock(TtsRateLimiter::class);
        $rateLimiter->shouldReceive('acquire')->andReturn(true);
        $service = new PronunciationService($provider, $rateLimiter);

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
        expect($result->pronunciation_status)->toBe(Word::PRONUNCIATION_GENERATED)
            ->and($result->pronunciation_retry_at)->toBeNull();
        expect($word->fresh()->pronunciation_status)->toBe(Word::PRONUNCIATION_GENERATED)
            ->and($word->fresh()->pronunciation_attempted_at)->not->toBeNull()
            ->and($word->fresh()->pronunciation_attempts)->toBe(0);
    });

    test('Given an unavailable provider, IPA is set but audio is skipped', function () {
        $provider = mock(TtsProvider::class);
        $provider->shouldReceive('isAvailable')->andReturn(false);
        $provider->shouldNotReceive('generateAudio');

        $service = new PronunciationService($provider, mock(TtsRateLimiter::class));

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
        expect($result->pronunciation_status)->toBe(Word::PRONUNCIATION_FAILED)
            ->and($result->pronunciation_attempts)->toBe(1)
            ->and($result->pronunciation_retry_at)->not->toBeNull()
            ->and($result->pronunciation_error)->toBe('tts_unavailable');
    });

    test('Given a failed TTS generation, audio_url stays null', function () {
        $provider = mock(\App\Contracts\TtsProvider::class);
        $provider->shouldReceive('isAvailable')->andReturn(true);
        $provider->shouldReceive('generateAudio')->andReturn(null);

        $rateLimiter = mock(TtsRateLimiter::class);
        $rateLimiter->shouldReceive('acquire')->andReturn(true);
        $service = new \App\Services\PronunciationService($provider, $rateLimiter);

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
        expect($result->pronunciation_status)->toBe(Word::PRONUNCIATION_FAILED)
            ->and($result->pronunciation_error)->toBe('audio_not_generated');
    });

    test('Given the TTS rate limit is occupied, audio generation is deferred without a failed attempt', function () {
        $provider = mock(TtsProvider::class);
        $provider->shouldReceive('isAvailable')->andReturn(true);
        $provider->shouldNotReceive('generateAudio');
        $rateLimiter = mock(TtsRateLimiter::class);
        $rateLimiter->shouldReceive('acquire')->andReturn(false);
        $rateLimiter->shouldReceive('nextAvailableAt')->andReturn(now()->addMinute());
        $service = new PronunciationService($provider, $rateLimiter);
        $word = Word::factory()->create(['text' => 'testword', 'slug' => 'testword']);

        $result = $service->ensurePronunciation($word);

        expect($result->pronunciation_status)->toBe(Word::PRONUNCIATION_PENDING)
            ->and($result->pronunciation_attempts)->toBe(0)
            ->and($result->pronunciation_retry_at)->not->toBeNull();
    });
});
