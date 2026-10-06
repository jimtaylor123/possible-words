<?php

use App\Exceptions\DefinitionGenerationException;
use App\Services\Definitions\GeminiDefinitionGenerator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.definitions_ai.gemini.key', 'test-key');
    config()->set('services.definitions_ai.gemini.base_url', 'https://example.test');
});

test('it returns a validated Gemini definition', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A pleasant made-up thing.","part_of_speech":"noun"}']]]]],
        ]),
    ]);

    $definition = app(GeminiDefinitionGenerator::class)->generate('blorg');

    expect($definition->text)->toBe('A pleasant made-up thing.')
        ->and($definition->partOfSpeech)->toBe('noun');
});

test('it rejects malformed or invalid Gemini content', function (string $content) {
    Http::fake([
        '*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $content]]]]]]),
    ]);

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow(DefinitionGenerationException::class);
})->with([
    'invalid json' => '{',
    'missing category' => '{"definition":"Meaning"}',
    'invalid category' => '{"definition":"Meaning","part_of_speech":"adjective"}',
    'empty definition' => '{"definition":" ","part_of_speech":"noun"}',
]);

test('it maps HTTP failures to a safe exception', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'secret']], 500)]);

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow('Gemini returned HTTP 500.');
});
