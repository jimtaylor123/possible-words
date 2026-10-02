<?php

use App\Services\DictionaryService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new DictionaryService;

    config()->set('dictionary.retry_sleep_ms', 0);
});

describe('banned word list', function () {
    test('Given the banned list file, it loads a non-empty word array', function () {
        $words = $this->service->loadBannedWords();

        expect($words)->toBeArray();
        expect($words)->not->toBeEmpty();
    });

    test('Given a banned word, it is detected; a made-up word is not', function () {
        expect($this->service->isInBannedList('the'))->toBeTrue();
        expect($this->service->isInBannedList('xyzzyn'))->toBeFalse();
    });

    test('Given the banned list is required and missing, loading it throws', function () {
        config()->set('dictionary.banned_words_path', '/tmp/does-not-exist-banned-words.json');
        config()->set('dictionary.banned_words_required', true);

        expect(fn () => (new DictionaryService)->loadBannedWords())
            ->toThrow(RuntimeException::class, 'Banned words list missing or empty');
    });

    test('Given the banned list is required and present, it loads without throwing', function () {
        config()->set('dictionary.banned_words_required', true);

        expect((new DictionaryService)->loadBannedWords())->not->toBeEmpty();
    });

    test('Given the banned list is missing but not required, loading it returns an empty array', function () {
        config()->set('dictionary.banned_words_path', '/tmp/does-not-exist-banned-words.json');
        config()->set('dictionary.banned_words_required', false);

        expect((new DictionaryService)->loadBannedWords())->toBe([]);
    });
});

describe('failing closed when the dictionary cannot be reached', function () {
    test('Given no source returned a verdict, the word is check_failed rather than not_found', function () {
        expect($this->service->classify([]))->toBe('check_failed');
        expect($this->service->classify([]))->not->toBe('not_found');
    });

    test('Given the API is unreachable, the word is check_failed with no sources', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(null, 500),
        ]);

        $result = $this->service->checkWord('xyzzyn');

        expect($result['status'])->toBe('check_failed');
        expect($result['sources'])->toBe([]);
        expect($result['errors'])->not->toBeEmpty();
    });

    test('Given a persistent Cloudflare 522, the word is check_failed after every attempt', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(null, 522),
        ]);

        $result = $this->service->checkWord('xyzzyn');

        expect($result['status'])->toBe('check_failed');
        Http::assertSentCount(3);
    });
});

describe('retrying a flaky dictionary lookup', function () {
    test('Given a lookup that recovers on the third attempt, the real verdict wins', function () {
        Http::fakeSequence()
            ->push('', 522)
            ->push('', 522)
            ->push([
                [
                    'word' => 'begin',
                    'meanings' => [
                        [
                            'partOfSpeech' => 'verb',
                            'definitions' => [
                                ['definition' => 'To start, to initiate or take the first step into something.'],
                            ],
                        ],
                    ],
                ],
            ], 200);

        $result = $this->service->checkWord('begin');

        expect($result['status'])->toBe('exists_as_word');
        Http::assertSentCount(3);
    });

    test('Given a 404, the lookup is never retried', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(null, 404),
        ]);

        $result = $this->service->checkWord('xyzzyn');

        expect($result['status'])->toBe('not_found');
        Http::assertSentCount(1);
    });

    test('Given a connection error on every attempt, the last failure is recorded', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(function () {
                throw new RuntimeException('Connection timed out');
            }),
        ]);

        $result = $this->service->checkWord('xyzzyn');

        expect($result['status'])->toBe('check_failed');
        expect($result['errors']['free_dictionary'])->toContain('Connection timed out');
        Http::assertSentCount(3);
    });
});

describe('classifying a word against the FreeDictionary API', function () {
    test('Given a 404, the word is classified as not_found', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(null, 404),
        ]);

        $result = $this->service->checkWord('xyzzyn');

        expect($result['status'])->toBe('not_found');
        expect($result['sources']['free_dictionary']['found'])->toBeFalse();
    });

    test('Given regular definitions, the word is classified as exists_as_word', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response([
                [
                    'word' => 'begin',
                    'meanings' => [
                        [
                            'partOfSpeech' => 'verb',
                            'definitions' => [
                                ['definition' => 'To start, to initiate or take the first step into something.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->checkWord('begin');

        expect($result['status'])->toBe('exists_as_word');
        expect($result['sources']['free_dictionary']['found'])->toBeTrue();
    });

    test('Given only proper noun entries, the word is classified as exists_as_name', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response([
                [
                    'word' => 'gurr',
                    'meanings' => [
                        [
                            'partOfSpeech' => 'proper noun',
                            'definitions' => [
                                ['definition' => 'A surname.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->checkWord('gurr');

        expect($result['status'])->toBe('exists_as_name');
        expect($result['sources']['free_dictionary']['found'])->toBeTrue();
    });

    test('Given mixed proper noun and regular definitions, the word is exists_as_word', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response([
                [
                    'word' => 'vibe',
                    'meanings' => [
                        [
                            'partOfSpeech' => 'proper noun',
                            'definitions' => [
                                ['definition' => 'A surname.'],
                            ],
                        ],
                        [
                            'partOfSpeech' => 'noun',
                            'definitions' => [
                                ['definition' => 'A distinctive atmosphere or quality.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->checkWord('vibe');

        expect($result['status'])->toBe('exists_as_word');
    });

    test('Given an API error, the lookup fails gracefully with null', function () {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::throw(function () {
                throw new Exception('Connection failed');
            }),
        ]);

        $result = $this->service->checkFreeDictionary('test');

        expect($result)->toBeNull();
    });
});

describe('classifying a word against Merriam-Webster', function () {
    test('Given a configured key and a match, the word is classified as exists_as_word', function () {
        config()->set('dictionary.merriam_webster_enabled', true);
        config()->set('dictionary.merriam_webster_key', 'test-key');

        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(null, 404),
            'www.dictionaryapi.com/*' => Http::response([
                ['meta' => ['id' => 'test'], 'shortdef' => ['A test definition']],
            ], 200),
        ]);

        $result = $this->service->checkWord('example');

        expect($result['status'])->toBe('exists_as_word');
        expect($result['sources']['merriam_webster']['found'])->toBeTrue();
    });

    test('Given no API key, the lookup returns null', function () {
        config()->set('dictionary.merriam_webster_key', '');

        $result = $this->service->checkMerriamWebster('test');

        expect($result)->toBeNull();
    });
});
