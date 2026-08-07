<?php

use App\Contracts\TtsProvider;
use App\Models\Word;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    $mock = mock(TtsProvider::class);
    $mock->shouldReceive('isAvailable')->andReturn(true);
    $mock->shouldReceive('generateAudio')->andReturn('http://localhost/audio/test.mp3');

    $this->app->instance(TtsProvider::class, $mock);
});

describe('pronunciation on the word detail page', function () {
    test('Given a word with pronunciation, the show page returns IPA and audio', function () {
        $word = Word::factory()->withPronunciation()->create();

        $this->get(route('words.show', $word))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Words/Show')
                ->has('word.ipa')
                ->has('word.audio_url')
            );
    });

    test('Given a word without pronunciation, the show page omits it', function () {
        $word = Word::factory()->create(['ipa' => null]);

        $this->get(route('words.show', $word))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Words/Show')
                ->where('word.ipa', null)
            );
    });
});

describe('generating pronunciation via artisan', function () {
    test('Given a word without pronunciation, the command generates it', function () {
        $word = Word::factory()->create([
            'phonemes' => [
                ['onset' => 'c', 'nucleus' => 'a', 'coda' => 't'],
            ],
            'ipa' => null,
            'audio_url' => null,
        ]);

        $this->artisan('words:generate-pronunciation')
            ->expectsOutputToContain('Generated pronunciation for 1 words.')
            ->assertExitCode(0);

        $word->refresh();
        expect($word->ipa)->toBe('/kæt/');
        expect($word->audio_url)->not->toBeNull();
    });

    test('Given words that already have pronunciation, the command skips them', function () {
        $word = Word::factory()->withPronunciation()->create([
            'ipa' => '/ɔld/',
        ]);

        $this->artisan('words:generate-pronunciation')
            ->expectsOutput('No words to process.')
            ->assertExitCode(0);
    });

    test('Given the --force flag, the command regenerates existing pronunciation', function () {
        $word = Word::factory()->withPronunciation()->create([
            'ipa' => '/ɔld/',
        ]);

        $this->artisan('words:generate-pronunciation --force')
            ->assertExitCode(0);

        $word->refresh();
        expect($word->ipa)->not->toBeNull();
    });
});
