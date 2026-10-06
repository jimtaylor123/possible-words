<?php

use App\Contracts\DefinitionGenerator;
use App\Data\GeneratedDefinition;
use App\Exceptions\DefinitionGenerationException;
use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it creates one attributed AI definition with a valid category', function () {
    $word = Word::factory()->create();
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()->with($word->text)
        ->andReturn(new GeneratedDefinition('A word for an unexpected delight.', 'noun'));

    app()->instance(DefinitionGenerator::class, $generator);
    app(GenerateWordDefinition::class, ['wordId' => $word->id])->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    $definition = $word->definitions()->where('origin', Definition::ORIGIN_AI)->firstOrFail();
    expect($definition->text)->toBe('A word for an unexpected delight.')
        ->and($definition->part_of_speech)->toBe('noun')
        ->and($definition->votes_count)->toBe(0)
        ->and($definition->user->name)->toBe('PossibleWords AI')
        ->and($word->fresh()->ai_definition_status)->toBe(Word::AI_DEFINITION_GENERATED);

    app(GenerateWordDefinition::class, ['wordId' => $word->id])->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));
    expect($word->definitions()->where('origin', Definition::ORIGIN_AI)->count())->toBe(1);
});

test('a generation failure leaves the word and records an identifiable failure', function () {
    $word = Word::factory()->create();
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->andThrow(new DefinitionGenerationException('Gemini request failed.'));
    $job = new GenerateWordDefinition($word->id);

    expect(fn () => $job->handle($generator, app(\App\Services\Definitions\SystemAiUser::class)))
        ->toThrow(DefinitionGenerationException::class);

    expect($word->fresh()->exists)->toBeTrue()
        ->and($word->fresh()->ai_definition_status)->toBe(Word::AI_DEFINITION_FAILED)
        ->and($word->fresh()->ai_definition_error)->toBe('Gemini request failed.')
        ->and($word->definitions()->count())->toBe(0);
});

test('it skips deleted words and words that already have an AI definition', function () {
    $deleted = Word::factory()->create();
    $deleted->delete();
    $complete = Word::factory()->create();
    $complete->definitions()->create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'text' => 'Existing AI definition',
        'origin' => Definition::ORIGIN_AI,
        'part_of_speech' => 'other',
    ]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldNotReceive('generate');

    app(GenerateWordDefinition::class, ['wordId' => $deleted->id])->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));
    app(GenerateWordDefinition::class, ['wordId' => $complete->id])->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));
});
