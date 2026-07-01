<?php

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $mock = mock(DictionaryService::class);
    $mock->shouldReceive('isInBannedList')->andReturn(false);
    $this->app->instance(DictionaryService::class, $mock);
});

test('words:generate creates the default number of words', function () {
    $this->artisan('words:generate')
        ->assertExitCode(0);

    expect(Word::count())->toBe(20);
});

test('words:generate creates specified number of words with --count', function () {
    $this->artisan('words:generate --count=50')
        ->assertExitCode(0);

    expect(Word::count())->toBe(50);
});

test('words:generate sets generated_at on created words', function () {
    $this->artisan('words:generate --count=5')
        ->assertExitCode(0);

    foreach (Word::all() as $word) {
        expect($word->generated_at)->not->toBeNull();
    }
});

test('words:generate with --fast flag creates words', function () {
    $this->artisan('words:generate --count=10 --fast')
        ->assertExitCode(0);

    expect(Word::count())->toBe(10);
});

test('words:generate skips existing duplicate words', function () {
    Word::factory()->create(['text' => 'zorp']);

    $this->artisan('words:generate --count=5')
        ->assertExitCode(0);

    expect(Word::count())->toBe(6);
});

test('words:generate outputs summary information', function () {
    $this->artisan('words:generate --count=5')
        ->expectsOutputToContain('fresh words planted')
        ->assertExitCode(0);
});

test('words:generate dispatches CheckWordDictionary job for normal mode', function () {
    $this->artisan('words:generate --count=3')
        ->assertExitCode(0);

    Queue::assertPushed(\App\Jobs\CheckWordDictionary::class, 3);
});

test('words:generate does not dispatch CheckWordDictionary job in fast mode', function () {
    $this->artisan('words:generate --count=3 --fast')
        ->assertExitCode(0);

    Queue::assertNotPushed(\App\Jobs\CheckWordDictionary::class);
});
