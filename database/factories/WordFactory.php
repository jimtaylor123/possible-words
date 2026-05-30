<?php

namespace Database\Factories;

use App\Models\Word;
use App\Services\WordGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WordFactory extends Factory
{
    protected $model = Word::class;

    public function definition(): array
    {
        $generator = new WordGenerator;
        $result = $generator->generateWords(1)[0];

        return [
            'text' => $result['text'],
            'phonemes' => $result['phonemes'],
            'syllables' => count($result['phonemes']),
            'status' => 'available',
            'slug' => Str::slug($result['text']),
        ];
    }

    public function withPronunciation(): static
    {
        return $this->state(fn (array $attributes) => [
            'ipa' => '/ˈtɛst/',
            'audio_url' => 'https://example.com/audio/'.Str::slug($attributes['text']).'.mp3',
        ]);
    }
}
