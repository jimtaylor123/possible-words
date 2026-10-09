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
            'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A pleasant made-up thing.","part_of_speech":"noun","example_sentence":"The blorg brought a smile to everyone."}']]]]],
        ]),
    ]);

    $definition = app(GeminiDefinitionGenerator::class)->generate('blorg');

    expect($definition->text)->toBe('A pleasant made-up thing.')
        ->and($definition->partOfSpeech)->toBe('noun')
        ->and($definition->exampleSentence)->toBe('The blorg brought a smile to everyone.');
});

test('it rejects malformed or invalid Gemini content', function (string $content) {
    Http::fake([
        '*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $content]]]]]]),
    ]);

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow(DefinitionGenerationException::class);
})->with([
    'invalid json' => '{',
    'missing category' => '{"definition":"Meaning","example_sentence":"A blorg appeared."}',
    'invalid category' => '{"definition":"Meaning","part_of_speech":"adjective","example_sentence":"A blorg appeared."}',
    'empty definition' => '{"definition":" ","part_of_speech":"noun","example_sentence":"A blorg appeared."}',
    'missing example' => '{"definition":"Meaning","part_of_speech":"noun"}',
    'blank example' => '{"definition":"Meaning","part_of_speech":"noun","example_sentence":" "}',
    'oversized example' => '{"definition":"Meaning","part_of_speech":"noun","example_sentence":"'.str_repeat('a', 501).' blorg"}',
    'example missing word' => '{"definition":"Meaning","part_of_speech":"noun","example_sentence":"A thing appeared."}',
]);

test('it maps HTTP failures to a safe exception', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'secret']], 500)]);

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow('Gemini returned HTTP 500.');
});

test('it recovers from sequential transient Gemini responses', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push(['error' => ['message' => 'unavailable']], 503)
            ->push([
                'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A recovered meaning.","part_of_speech":"noun","example_sentence":"The recovered blorg shines."}']]]]],
            ]),
    ]);

    $definition = app(GeminiDefinitionGenerator::class)->generate('blorg');

    expect($definition->text)->toBe('A recovered meaning.')
        ->and($definition->partOfSpeech)->toBe('noun')
        ->and($definition->exampleSentence)->toBe('The recovered blorg shines.');
    Http::assertSentCount(3);
});

test('it stops after exhausting transient Gemini retries', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => 'server error']], 500)
            ->push(['error' => ['message' => 'bad gateway']], 502)
            ->push(['error' => ['message' => 'gateway timeout']], 504),
    ]);

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow(DefinitionGenerationException::class, 'Gemini returned HTTP 504.');
    Http::assertSentCount(3);
});

test('it does not retry non-retryable Gemini responses', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => 'invalid request']], 400)
            ->push(['error' => ['message' => 'should not be requested']], 500),
    ]);

    expect(fn () => app(GeminiDefinitionGenerator::class)->generate('blorg'))
        ->toThrow(DefinitionGenerationException::class, 'Gemini returned HTTP 400.');
    Http::assertSentCount(1);
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
            'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A pleasant made-up thing.","part_of_speech":"noun","example_sentence":"The blorg brought a smile to everyone."}']]]]],
        ]),
    ]);

    app(GeminiDefinitionGenerator::class)->generate('blorg');

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key');
    });
});

test('it requests a required example sentence that uses the supplied word', function () {
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A pleasant made-up thing.","part_of_speech":"noun","example_sentence":"The blorg brought a smile to everyone."}']]]]],
    ])]);

    app(GeminiDefinitionGenerator::class)->generate('blorg');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['generationConfig']['responseSchema']['required'] === ['definition', 'part_of_speech', 'example_sentence']
            && str_contains($body['contents'][0]['parts'][0]['text'], "using 'blorg'");
    });
});

test('it requests only an example sentence for an established definition', function () {
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"example_sentence":"The blorg brought a smile to everyone."}']]]]],
    ])]);

    $exampleSentence = app(GeminiDefinitionGenerator::class)->generateExampleSentence(
        'blorg',
        'A pleasant made-up thing.',
        'noun',
    );

    expect($exampleSentence)->toBe('The blorg brought a smile to everyone.');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['generationConfig']['responseSchema']['required'] === ['example_sentence']
            && str_contains($body['contents'][0]['parts'][0]['text'], 'A pleasant made-up thing.')
            && str_contains($body['contents'][0]['parts'][0]['text'], 'Do not rewrite the definition.');
    });
});

test('it uses the supported Gemini definition model by default', function () {
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"definition":"A pleasant made-up thing.","part_of_speech":"noun","example_sentence":"The blorg brought a smile to everyone."}']]]]],
    ])]);

    app(GeminiDefinitionGenerator::class)->generate('blorg');

    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/v1beta/models/gemini-flash-lite-latest:generateContent');
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

test('it allows Gemini enough time to respond by default', function () {
    expect(app(GeminiDefinitionGenerator::class)->requestTimeout())
        ->toBe(45)
        ->and(GenerateWordDefinition::TIMEOUT)->toBe(50);
});

test('it caps the request timeout below the queue job timeout', function () {
    config()->set('services.definitions_ai.timeout', 90);

    $timeout = app(GeminiDefinitionGenerator::class)->requestTimeout();

    expect($timeout)->toBe(GenerateWordDefinition::TIMEOUT - GeminiDefinitionGenerator::TIMEOUT_BUFFER)
        ->and($timeout)->toBeLessThan(GenerateWordDefinition::TIMEOUT);
});
