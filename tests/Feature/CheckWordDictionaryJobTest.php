<?php

use App\Jobs\CheckWordDictionary;
use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Support\Facades\Http;

/**
 * The queued dictionary check for a single word.
 *
 * The job must record a verdict only when a lookup actually answered, and must
 * leave a failed check re-checkable rather than promoting it to a confirmed
 * non-word.
 */
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('dictionary.throttle_per_second', 0);
    config()->set('dictionary.retry_sleep_ms', 0);
});

function jobWord(array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 1,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_UNCHECKED,
    ], $attrs));
}

describe('checking a queued word', function () {
    test('Given an unchecked word, the job writes the verdict', function () {
        $word = jobWord();

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 404)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        $word->refresh();

        expect($word->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
        expect($word->dictionary_checked_at)->not->toBeNull();
        expect($word->dictionary_data)->toBe(['free_dictionary' => ['found' => false]]);
    });

    test('Given a word whose last check failed, the job retries it', function () {
        $word = jobWord(['dictionary_status' => Word::DICTIONARY_CHECK_FAILED]);

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 404)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_NOT_FOUND);
    });

    test('Given an already-checked word, the job leaves it alone', function () {
        $word = jobWord(['dictionary_status' => Word::DICTIONARY_NOT_FOUND]);

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 404)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        expect($word->fresh()->dictionary_checked_at)->toBeNull();
    });

    test('Given a confirmed real word, the job leaves it alone', function () {
        $word = jobWord(['dictionary_status' => Word::DICTIONARY_EXISTS_AS_WORD]);

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 404)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        expect($word->fresh()->dictionary_checked_at)->toBeNull();
    });

    test('Given a withdrawn word, the job skips it', function () {
        $word = jobWord();
        $word->delete();

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 404)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        expect($word->fresh()->dictionary_status)->toBe(Word::DICTIONARY_UNCHECKED);
        Http::assertNothingSent();
    });
});

describe('when the lookup fails', function () {
    test('Given an unreachable API, the job records check_failed and never not_found', function () {
        $word = jobWord();

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 500)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        $word->refresh();

        expect($word->dictionary_status)->toBe(Word::DICTIONARY_CHECK_FAILED);
        expect($word->dictionary_status)->not->toBe(Word::DICTIONARY_NOT_FOUND);
        expect($word->dictionary_data)->toBe([]);
    });

    test('Given a failed lookup, the word stays off the public list', function () {
        $word = jobWord();

        Http::fake(['api.dictionaryapi.dev/*' => Http::response(null, 500)]);

        (new CheckWordDictionary($word))->handle(app(DictionaryService::class));

        expect($word->fresh()->isPublishable())->toBeFalse();
    });
});
