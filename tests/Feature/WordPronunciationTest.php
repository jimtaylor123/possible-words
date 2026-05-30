<?php

use App\Models\Word;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');

    Http::fake([
        'api.openai.com/*' => Http::response('fake-mp3-content', 200),
    ]);

    config(['services.openai.key' => 'sk-test']);
});

it('show page returns word with pronunciation data', function () {
    $word = Word::factory()->withPronunciation()->create();

    $this->get(route('words.show', $word))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Words/Show')
            ->has('word.ipa')
            ->has('word.audio_url')
        );
});

it('artisan command generates pronunciation for words without it', function () {
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

it('artisan command skips words that already have pronunciation', function () {
    $word = Word::factory()->withPronunciation()->create([
        'ipa' => '/ɔld/',
    ]);

    $this->artisan('words:generate-pronunciation')
        ->expectsOutput('No words to process.')
        ->assertExitCode(0);
});

it('artisan command regenerates with force flag', function () {
    $word = Word::factory()->withPronunciation()->create([
        'ipa' => '/ɔld/',
    ]);

    $originalIpa = $word->ipa;

    $this->artisan('words:generate-pronunciation --force')
        ->assertExitCode(0);

    $word->refresh();
    expect($word->ipa)->not->toBeNull();
});

it('show page returns word without pronunciation when not set', function () {
    $word = Word::factory()->create(['ipa' => null]);

    $this->get(route('words.show', $word))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Words/Show')
            ->where('word.ipa', null)
        );
});
