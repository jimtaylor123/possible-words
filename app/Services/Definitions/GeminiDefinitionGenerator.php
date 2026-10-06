<?php

namespace App\Services\Definitions;

use App\Contracts\DefinitionGenerator;
use App\Data\GeneratedDefinition;
use App\Exceptions\DefinitionGenerationException;
use Illuminate\Support\Facades\Http;

class GeminiDefinitionGenerator implements DefinitionGenerator
{
    public function generate(string $word): GeneratedDefinition
    {
        $key = config('services.definitions_ai.gemini.key');
        if (! is_string($key) || $key === '') {
            throw new DefinitionGenerationException('Gemini API key is not configured.');
        }

        try {
            $response = Http::timeout((int) config('services.definitions_ai.timeout', 20))
                ->acceptJson()
                ->post($this->endpoint($key), [
                    'contents' => [[
                        'parts' => [[
                            'text' => "Invent a concise, dictionary-style meaning for the made-up word '{$word}'. Return JSON only.",
                        ]],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'definition' => ['type' => 'STRING'],
                                'part_of_speech' => ['type' => 'STRING', 'enum' => ['noun', 'verb', 'other']],
                            ],
                            'required' => ['definition', 'part_of_speech'],
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
            $definition = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DefinitionGenerationException('Gemini returned invalid JSON.', previous: $exception);
        }

        $text = is_array($definition) && is_string($definition['definition'] ?? null)
            ? trim($definition['definition'])
            : '';
        $partOfSpeech = is_array($definition) && is_string($definition['part_of_speech'] ?? null)
            ? $definition['part_of_speech']
            : '';

        if ($text === '' || mb_strlen($text) > 1000 || ! in_array($partOfSpeech, ['noun', 'verb', 'other'], true)) {
            throw new DefinitionGenerationException('Gemini returned an invalid definition.');
        }

        return new GeneratedDefinition($text, $partOfSpeech);
    }

    private function endpoint(string $key): string
    {
        $baseUrl = rtrim((string) config('services.definitions_ai.gemini.base_url'), '/');
        $model = config('services.definitions_ai.gemini.model', 'gemini-2.5-flash');

        return "{$baseUrl}/v1beta/models/{$model}:generateContent?key=".urlencode($key);
    }
}
