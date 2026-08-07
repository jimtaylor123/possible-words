<?php

use App\Services\DictionaryService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new DictionaryService;
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
