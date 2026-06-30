<?php

use App\Services\DictionaryService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new DictionaryService;
});

it('loads banned words from JSON file', function () {
    $words = $this->service->loadBannedWords();

    expect($words)->toBeArray();
    expect($words)->not->toBeEmpty();
});

it('detects banned words', function () {
    expect($this->service->isInBannedList('the'))->toBeTrue();
    expect($this->service->isInBannedList('xyzzyn'))->toBeFalse();
});

it('classifies not_found when FreeDictionary returns 404', function () {
    Http::fake([
        'api.dictionaryapi.dev/*' => Http::response(null, 404),
    ]);

    $result = $this->service->checkWord('xyzzyn');

    expect($result['status'])->toBe('not_found');
    expect($result['sources']['free_dictionary']['found'])->toBeFalse();
});

it('classifies exists_as_word when FreeDictionary returns definitions', function () {
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

it('classifies exists_as_name when only proper noun entries exist', function () {
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

it('classifies exists_as_word when mixed proper noun and regular definitions exist', function () {
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

it('classifies exists_as_word when found in Merriam-Webster', function () {
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

it('returns null for Merriam-Webster when no API key configured', function () {
    config()->set('dictionary.merriam_webster_key', '');

    $result = $this->service->checkMerriamWebster('test');

    expect($result)->toBeNull();
});

it('handles FreeDictionary API errors gracefully', function () {
    Http::fake([
        'api.dictionaryapi.dev/*' => Http::throw(function () {
            throw new Exception('Connection failed');
        }),
    ]);

    $result = $this->service->checkFreeDictionary('test');

    expect($result)->toBeNull();
});
