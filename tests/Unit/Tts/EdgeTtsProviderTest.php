<?php

use App\Models\Word;
use App\Services\Tts\EdgeTtsProvider;
use Bestmomo\LaravelEdgeTts\Facades\EdgeTts;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

it('returns null when synthesis fails', function () {
    EdgeTts::shouldReceive('synthesize')
        ->andThrow(new \Exception('Connection failed'));

    $word = Word::factory()->create(['slug' => 'test']);

    $provider = new EdgeTtsProvider;
    $result = $provider->generateAudio($word);

    expect($result)->toBeNull();
});

it('stores audio on successful synthesis', function () {
    EdgeTts::shouldReceive('synthesize')
        ->andReturn('fake-mp3-content');

    $word = Word::factory()->create(['slug' => 'testword']);

    $provider = new EdgeTtsProvider;
    $result = $provider->generateAudio($word);

    Storage::disk(config('filesystems.default'))->assertExists('audio/testword.mp3');
    expect($result)->not->toBeNull();
});

it('returns null when audio data is empty', function () {
    EdgeTts::shouldReceive('synthesize')
        ->andReturn('');

    $word = Word::factory()->create(['slug' => 'test']);

    $provider = new EdgeTtsProvider;
    $result = $provider->generateAudio($word);

    expect($result)->toBeNull();
});
