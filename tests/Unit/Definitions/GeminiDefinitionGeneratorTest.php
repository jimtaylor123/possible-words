<?php

use App\Exceptions\DefinitionGenerationException;
use App\Jobs\GenerateWordDefinition;
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

test('it rejects a missing Gemini API key before sending anything', function () {
    config()->set('services.definitions_ai.gemini.key', null);
    Http::fake();

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow(DefinitionGenerationException::class, 'Gemini API key is not configured.');

    Http::assertNothingSent();
});

test('it sends the API key as a header, never in the URL', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A pleasant made-up thing.","part_of_speech":"noun"}']]]]],
        ]),
    ]);

    app(GeminiDefinitionGenerator::class)->generate('blorg');

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key');
    });
});

test('it never exposes the API key in thrown exception messages', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'secret']], 500)]);

    try {
        app(GeminiDefinitionGenerator::class)->generate('blorg');
        $this->fail('Expected a DefinitionGenerationException.');
    } catch (DefinitionGenerationException $exception) {
        $messages = '';
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $messages .= $e->getMessage()."\n";
        }

        expect($messages)->not->toContain('test-key');
    }
});

test('it caps the request timeout below the queue job timeout', function () {
    config()->set('services.definitions_ai.timeout', 90);

    $timeout = app(GeminiDefinitionGenerator::class)->requestTimeout();

    expect($timeout)->toBe(GenerateWordDefinition::TIMEOUT - GeminiDefinitionGenerator::TIMEOUT_BUFFER)
        ->and($timeout)->toBeLessThan(GenerateWordDefinition::TIMEOUT);
});
