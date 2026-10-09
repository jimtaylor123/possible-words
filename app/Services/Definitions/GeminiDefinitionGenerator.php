<?php

namespace App\Services\Definitions;

use App\Contracts\DefinitionGenerator;
use App\Data\GeneratedDefinition;
use App\Exceptions\DefinitionGenerationException;
use App\Jobs\GenerateWordDefinition;
use Illuminate\Support\Facades\Http;

class GeminiDefinitionGenerator implements DefinitionGenerator
{
    public const TIMEOUT_BUFFER = 5;

    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAYS_MS = [100, 200];

    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    public function generate(string $word): GeneratedDefinition
    {
        $key = config('services.definitions_ai.gemini.key');
        if (! is_string($key) || $key === '') {
            throw new DefinitionGenerationException('Gemini API key is not configured.');
        }

        try {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                // The key travels as a header, never in the URL: URLs end up in
                // exception messages and traces, which are logged on failure.
                $response = Http::timeout($this->requestTimeout())
                    ->acceptJson()
                    ->withHeaders(['x-goog-api-key' => $key])
                    ->post($this->endpoint(), [
                        'contents' => [[
                            'parts' => [[
                                'text' => "Invent a concise, dictionary-style meaning for the made-up word '{$word}', then write one concise standalone example sentence using '{$word}' according to that meaning and part of speech. Return JSON only.",
                            ]],
                        ]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'definition' => ['type' => 'STRING'],
                                    'part_of_speech' => ['type' => 'STRING', 'enum' => ['noun', 'verb', 'other']],
                                    'example_sentence' => ['type' => 'STRING'],
                                ],
                                'required' => ['definition', 'part_of_speech', 'example_sentence'],
                            ],
                        ],
                    ]);

                if (! in_array($response->status(), self::RETRYABLE_STATUS_CODES, true) || $attempt === self::MAX_ATTEMPTS) {
                    break;
                }

                usleep(self::RETRY_DELAYS_MS[$attempt - 1] * 1000);
            }
        } catch (\Throwable $exception) {
            throw new DefinitionGenerationException('Gemini request failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new DefinitionGenerationException('Gemini returned HTTP '.$response->status().'.');
        }

        $content = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($content)) {
            throw new DefinitionGenerationException('Gemini returned no definition content.');
        }

        try {
            $definition = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DefinitionGenerationException('Gemini returned invalid JSON.', previous: $exception);
        }

        $text = is_array($definition) && is_string($definition['definition'] ?? null)
            ? trim($definition['definition'])
            : '';
        $partOfSpeech = is_array($definition) && is_string($definition['part_of_speech'] ?? null) ? trim($definition['part_of_speech']) : '';
        $exampleSentence = is_array($definition) && is_string($definition['example_sentence'] ?? null) ? trim($definition['example_sentence']) : '';

        if (! $this->isValidDefinition($text, $partOfSpeech) || ! $this->isValidExampleSentence($exampleSentence, $word)) {
            throw new DefinitionGenerationException('Gemini returned an invalid definition.');
        }

        return new GeneratedDefinition($text, $partOfSpeech, $exampleSentence);
    }

    public function generateExampleSentence(string $word, string $definition, string $partOfSpeech): string
    {
        $key = config('services.definitions_ai.gemini.key');
        if (! is_string($key) || $key === '') {
            throw new DefinitionGenerationException('Gemini API key is not configured.');
        }

        try {
            $response = Http::timeout($this->requestTimeout())
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $key])
                ->post($this->endpoint(), [
                    'contents' => [[
                        'parts' => [[
                            'text' => "Write one concise standalone example sentence using the made-up word '{$word}' as a {$partOfSpeech}, according to this established definition: '{$definition}'. Return JSON only. Do not rewrite the definition.",
                        ]],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => ['example_sentence' => ['type' => 'STRING']],
                            'required' => ['example_sentence'],
                        ],
                    ],
                ]);
        } catch (\Throwable $exception) {
            throw new DefinitionGenerationException('Gemini request failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new DefinitionGenerationException('Gemini returned HTTP '.$response->status().'.');
        }

        $content = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($content)) {
            throw new DefinitionGenerationException('Gemini returned no definition content.');
        }

        try {
            $responseBody = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DefinitionGenerationException('Gemini returned invalid JSON.', previous: $exception);
        }

        $exampleSentence = is_array($responseBody) && is_string($responseBody['example_sentence'] ?? null)
            ? trim($responseBody['example_sentence'])
            : '';

        if (! $this->isValidExampleSentence($exampleSentence, $word)) {
            throw new DefinitionGenerationException('Gemini returned an invalid example sentence.');
        }

        return $exampleSentence;
    }

    private function isValidDefinition(string $text, string $partOfSpeech): bool
    {
        return $text !== '' && mb_strlen($text) <= 1000 && in_array($partOfSpeech, ['noun', 'verb', 'other'], true);
    }

    private function isValidExampleSentence(string $exampleSentence, string $word): bool
    {
        return $exampleSentence !== ''
            && mb_strlen($exampleSentence) <= 500
            && mb_stripos($exampleSentence, $word) !== false;
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim((string) config('services.definitions_ai.gemini.base_url'), '/');
        $model = config('services.definitions_ai.gemini.model', 'gemini-flash-lite-latest');

        return "{$baseUrl}/v1beta/models/{$model}:generateContent";
    }

    public function requestTimeout(): int
    {
        $configuredTimeout = (int) config('services.definitions_ai.timeout', 20);

        return min(
            max($configuredTimeout, 1),
            GenerateWordDefinition::TIMEOUT - self::TIMEOUT_BUFFER,
        );
    }
}
