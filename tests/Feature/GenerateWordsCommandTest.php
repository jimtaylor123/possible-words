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

describe('generating words via artisan', function () {
    test('Given the command runs, it creates the default number of words', function () {
        $this->artisan('words:generate')
            ->assertExitCode(0);

        expect(Word::count())->toBe(20);
    });

    test('Given a --count option, it creates that many words', function () {
        $this->artisan('words:generate --count=50')
            ->assertExitCode(0);

        expect(Word::count())->toBe(50);
    });

    test('Given the command runs, created words have a generated_at timestamp', function () {
        $this->artisan('words:generate --count=5')
            ->assertExitCode(0);

        foreach (Word::all() as $word) {
            expect($word->generated_at)->not->toBeNull();
        }
    });

    test('Given the --fast flag, it creates words without dictionary checks', function () {
        $this->artisan('words:generate --count=10 --fast')
            ->assertExitCode(0);

        expect(Word::count())->toBe(10);
    });

    test('Given an existing duplicate word, generation skips it', function () {
        Word::factory()->create(['text' => 'zorp']);

        $this->artisan('words:generate --count=5')
            ->assertExitCode(0);

        expect(Word::count())->toBe(6);
    });

    test('Given the command runs, it prints a summary', function () {
        $this->artisan('words:generate --count=5')
            ->expectsOutputToContain('fresh words planted')
            ->assertExitCode(0);
    });

    test('Given normal mode, it dispatches a dictionary check per word', function () {
        $this->artisan('words:generate --count=3')
            ->assertExitCode(0);

        Queue::assertPushed(\App\Jobs\CheckWordDictionary::class, 3);
    });

    test('Given --fast mode, it skips dictionary check jobs', function () {
        $this->artisan('words:generate --count=3 --fast')
            ->assertExitCode(0);

        Queue::assertNotPushed(\App\Jobs\CheckWordDictionary::class);
    });
});
