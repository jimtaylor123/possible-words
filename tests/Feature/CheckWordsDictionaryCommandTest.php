<?php

use App\Models\Word;
use Illuminate\Support\Facades\Http;

/**
 * Repairing the rows the old fail-open classifier created.
 *
 * words:check-dictionary must revisit words whose verdict came from a failed
 * lookup, must never turn a failed lookup into 'not_found', and must report
 * which words it takes out of the public list.
 */
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function checkableWord(string $text, array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => $text,
        'slug' => $text,
        'syllables' => 1,
        'status' => 'available',
        'published_at' => now(),
    ], $attrs));
}

function notFoundResponse(): array
{
    return ['api.dictionaryapi.dev/*' => Http::response(null, 404)];
}

function existsResponse(): array
{
    return ['api.dictionaryapi.dev/*' => Http::response([
        [
            'word' => 'begin',
            'meanings' => [
                [
                    'partOfSpeech' => 'verb',
                    'definitions' => [['definition' => 'To start.']],
                ],
            ],
        ],
    ], 200)];
}

beforeEach(function () {
    config()->set('dictionary.throttle_per_second', 0);
    config()->set('dictionary.retry_sleep_ms', 0);
});

describe('choosing which words to check', function () {
    test('Given an unchecked word, it is checked by default', function () {
        $word = checkableWord('pending', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary')->assertExitCode(0);

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
    });

    test('Given a failed check, it is retried by default', function () {
        $word = checkableWord('failed', ['dictionary_status' => Word::DICTIONARY_CHECK_FAILED]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary')->assertExitCode(0);

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
    });

    test('Given an already-checked word, it is left alone by default', function () {
        $word = checkableWord('done', [
            'dictionary_status' => Word::DICTIONARY_EXISTS_AS_WORD,
            'dictionary_data' => ['free_dictionary' => ['found' => true]],
        ]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary')
            ->expectsOutputToContain('No words to check')
            ->assertExitCode(0);

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_EXISTS_AS_WORD);
    });

    test('Given more words than a chunk, every unchecked word is checked', function () {
        $first = checkableWord('first', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);
        $second = checkableWord('second', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);
        $third = checkableWord('third', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary --chunk=1')->assertExitCode(0);

        expect($first->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND)
            ->and($second->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND)
            ->and($third->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
    });

    test('Given --force, an already-checked word is re-checked', function () {
        $word = checkableWord('done', [
            'dictionary_status' => Word::DICTIONARY_EXISTS_AS_WORD,
            'dictionary_data' => ['free_dictionary' => ['found' => true]],
        ]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary --force')->assertExitCode(0);

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
    });
});

describe('retrying the words the old fail-open classifier created', function () {
    test('Given --retry-failed, a not_found word with no verdict recorded is re-checked', function () {
        $word = checkableWord('unverified', [
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'dictionary_data' => [],
        ]);

        Http::fake(existsResponse());

        $this->artisan('words:check-dictionary --retry-failed')->assertExitCode(0);

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_EXISTS_AS_WORD);
    });

    test('Given --retry-failed, a not_found word with a real verdict is not re-checked', function () {
        $word = checkableWord('verified', [
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'dictionary_data' => ['free_dictionary' => ['found' => false]],
        ]);

        Http::fake(existsResponse());

        $this->artisan('words:check-dictionary --retry-failed')
            ->expectsOutputToContain('No words to check')
            ->assertExitCode(0);

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
    });

    test('Given --retry-failed and nothing to repair, the command says so', function () {
        checkableWord('verified', [
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'dictionary_data' => ['free_dictionary' => ['found' => false]],
        ]);

        $this->artisan('words:check-dictionary --retry-failed')
            ->expectsOutputToContain('No words to check')
            ->assertExitCode(0);
    });

    test('Given --retry-failed, the withdrawn words are skipped', function () {
        $word = checkableWord('gone', [
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'dictionary_data' => [],
        ]);
        $word->delete();

        $this->artisan('words:check-dictionary --retry-failed')
            ->expectsOutputToContain('No words to check')
            ->assertExitCode(0);
    });
});

describe('never recording a verdict for a failed lookup', function () {
    test('Given the API is down, the word stays check_failed and never becomes not_found', function () {
        $word = checkableWord('unreachable', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 500)]);

        $this->artisan('words:check-dictionary')->assertExitCode(0);

        $word->refresh();

        expect($word->dictionary_status)->toBe(Word::DICTIONARY_CHECK_FAILED);
        expect($word->dictionary_status)->not->toBe(Word::DICTIONARY_NOT_FOUND);
        expect($word->dictionary_data)->toBe([]);
    });

    test('Given the API is down, the summary says the word is still hidden', function () {
        checkableWord('unreachable', ['dictionary_status' => Word::DICTIONARY_UNCHECKED]);

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 500)]);

        $this->artisan('words:check-dictionary')
            ->expectsOutputToContain('still hidden')
            ->assertExitCode(0);
    });
});

describe('reporting what changed on the public list', function () {
    test('Given a word turns out to be a real word, the summary names it', function () {
        checkableWord('fun', [
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'dictionary_data' => [],
        ]);

        Http::fake(existsResponse());

        $this->artisan('words:check-dictionary --retry-failed')
            ->expectsOutputToContain('Left the public list')
            ->expectsOutputToContain('fun')
            ->assertExitCode(0);
    });

    test('Given a failed word becomes a confirmed non-word, the summary names it', function () {
        checkableWord('recovered', ['dictionary_status' => Word::DICTIONARY_CHECK_FAILED]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary')
            ->expectsOutputToContain('Joined the public list')
            ->expectsOutputToContain('recovered')
            ->assertExitCode(0);
    });

    test('Given a re-check changes no verdict, no public list section is printed', function () {
        // Already publishable, and stays publishable, so nothing enters or leaves.
        checkableWord('steady', [
            'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
            'dictionary_data' => ['free_dictionary' => ['found' => false]],
        ]);

        Http::fake(notFoundResponse());

        $this->artisan('words:check-dictionary --force')
            ->doesntExpectOutputToContain('Left the public list')
            ->doesntExpectOutputToContain('Joined the public list')
            ->assertExitCode(0);
    });
});
