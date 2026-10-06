<?php

use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it requires a positive limit no greater than twenty', function (string $limit) {
    Queue::fake();
    Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);

    $this->artisan('words:generate-definitions', ['--limit' => $limit])
        ->expectsOutput('--limit must be a positive integer no greater than 20.')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
})->with(['zero' => '0', 'over the cap' => '21', 'non-numeric' => 'two']);

test('it requires the limit option', function () {
    Queue::fake();
    Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);

    $this->artisan('words:generate-definitions')
        ->expectsOutput('--limit must be a positive integer no greater than 20.')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it deterministically queues only the requested number of pending words that lack an AI definition', function () {
    Queue::fake();
    $firstPending = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);
    $secondPending = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);
    $thirdPending = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);
    $failed = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_FAILED]);
    $complete = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_GENERATED]);
    $complete->definitions()->create([
        'user_id' => User::factory()->create()->id,
        'text' => 'Done',
        'origin' => Definition::ORIGIN_AI,
        'part_of_speech' => 'noun',
    ]);

    $this->artisan('words:generate-definitions', ['--limit' => '2'])
        ->expectsOutput('Queued 2 missing AI definition(s).')
        ->assertExitCode(0);

    Queue::assertPushed(GenerateWordDefinition::class, 2);
    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $firstPending->id);
    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $secondPending->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $thirdPending->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $failed->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $complete->id);
});

test('it includes failed words only when explicitly requested and still respects the limit', function () {
    Queue::fake();
    $firstFailed = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_FAILED]);
    $pending = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_PENDING]);
    $secondFailed = Word::factory()->create(['ai_definition_status' => Word::AI_DEFINITION_FAILED]);

    $this->artisan('words:generate-definitions', ['--limit' => '2'])->assertExitCode(0);

    Queue::assertPushed(GenerateWordDefinition::class, 1);
    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $pending->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $firstFailed->id);

    Queue::fake();

    $this->artisan('words:generate-definitions', ['--limit' => '2', '--retry-failed' => true])->assertExitCode(0);

    Queue::assertPushed(GenerateWordDefinition::class, 2);
    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $firstFailed->id);
    Queue::assertPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $pending->id);
    Queue::assertNotPushed(GenerateWordDefinition::class, fn ($job) => $job->wordId === $secondFailed->id);
});
