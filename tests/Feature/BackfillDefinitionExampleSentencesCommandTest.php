<?php

use App\Jobs\GenerateDefinitionExampleSentence;
use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it requires a bounded explicit limit', function (string $limit) {
    Queue::fake();

    $this->artisan('definitions:backfill-example-sentences', ['--limit' => $limit])
        ->expectsOutput('--limit must be a positive integer no greater than 10.')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
})->with(['missing' => '', 'zero' => '0', 'over the cap' => '11']);

test('it queues only missing AI examples in deterministic bounded order', function () {
    Queue::fake();
    $first = legacyAiDefinition();
    $second = legacyAiDefinition();
    $third = legacyAiDefinition();
    legacyAiDefinition('Already complete.');
    $human = legacyAiDefinition();
    $human->update(['origin' => null]);

    $this->artisan('definitions:backfill-example-sentences', ['--limit' => '2'])
        ->expectsOutput('Queued 2 missing AI example sentence(s).')
        ->assertExitCode(0);

    Queue::assertPushed(GenerateDefinitionExampleSentence::class, 2);
    Queue::assertPushed(GenerateDefinitionExampleSentence::class, fn ($job) => $job->definitionId === $first->id);
    Queue::assertPushed(GenerateDefinitionExampleSentence::class, fn ($job) => $job->definitionId === $second->id);
    Queue::assertNotPushed(GenerateDefinitionExampleSentence::class, fn ($job) => $job->definitionId === $third->id);
    Queue::assertNotPushed(GenerateDefinitionExampleSentence::class, fn ($job) => $job->definitionId === $human->id);
});

function legacyAiDefinition(?string $exampleSentence = null): Definition
{
    $word = Word::factory()->create();

    return $word->definitions()->create([
        'user_id' => User::factory()->create()->id,
        'text' => 'A legacy definition.',
        'part_of_speech' => 'noun',
        'example_sentence' => $exampleSentence,
        'origin' => Definition::ORIGIN_AI,
    ]);
}
