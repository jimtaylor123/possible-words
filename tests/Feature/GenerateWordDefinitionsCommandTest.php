<?php

use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it queues only pending words that lack an AI definition', function () {
    Queue::fake();
    $pending = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);
    $failed = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_FAILED]);
    $complete = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_GENERATED]);
    $complete->definitions()->create([
        'user_id' => User::factory()->create()->id,
        'text' => 'Done',
        'origin' => Definition::ORIGIN_AI,
        'part_of_speech' => 'noun',
    ]);

    $this->artisan('words:generate-definitions')
        ->expectsOutput('Queued 1 missing AI definition(s).')
        ->assertExitCode(0);

    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $pending->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $failed->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $complete->id);
});

test('it includes failed words only when explicitly requested', function () {
    Queue::fake();
    $failed = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_FAILED]);

    $this->artisan('words:generate-definitions --retry-failed')->assertExitCode(0);

    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $failed->id);
});
