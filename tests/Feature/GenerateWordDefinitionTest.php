<?php

use App\Contracts\DefinitionGenerator;
use App\Data\GeneratedDefinition;
use App\Events\DefinitionCreated;
use App\Exceptions\DefinitionGenerationException;
use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;
use App\Models\WordBatch;
use App\Models\WordBatchSlot;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it creates one attributed AI definition with a valid category', function () {
    $word = Word::factory()->create();
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()->with($word->text, [])
        ->andReturn(new GeneratedDefinition('A word for an unexpected delight.', 'noun', 'A blorg made the gathering brighter.'));

    app()->instance(DefinitionGenerator::class, $generator);
    app(GenerateWordDefinition::class, ['wordId' => $word->id])->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    $definition = $word->definitions()->where('origin', Definition::ORIGIN_AI)->firstOrFail();
    expect($definition->text)->toBe('A word for an unexpected delight.')
        ->and($definition->part_of_speech)->toBe('noun')
        ->and($definition->example_sentence)->toBe('A blorg made the gathering brighter.')
        ->and($definition->votes_count)->toBe(0)
        ->and($definition->user->name)->toBe('PossibleWords AI')
        ->and($word->fresh()->ai_definition_status)->toBe(Word::AI_DEFINITION_GENERATED);

    app(GenerateWordDefinition::class, ['wordId' => $word->id])->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));
    expect($word->definitions()->where('origin', Definition::ORIGIN_AI)->count())->toBe(1);
});

test('it broadcasts newly generated AI definitions only for publishable words', function () {
    Event::fake([DefinitionCreated::class]);
    $unpublishedWord = Word::factory()->create(['published_at' => null]);
    $publishableWord = Word::factory()->create([
        'status' => 'available',
        'published_at' => now(),
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
    ]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->twice()
        ->andReturn(new GeneratedDefinition('A generated meaning.', 'noun', 'A blorg has a meaning.'));

    (new GenerateWordDefinition($unpublishedWord->id))->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    expect($unpublishedWord->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeTrue();
    Event::assertNotDispatched(DefinitionCreated::class);

    (new GenerateWordDefinition($publishableWord->id))->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    $definition = $publishableWord->definitions()->where('origin', Definition::ORIGIN_AI)->firstOrFail();
    Event::assertDispatched(DefinitionCreated::class, function (DefinitionCreated $event) use ($definition) {
        return $event->definition->is($definition)
            && $event->definition->text === 'A generated meaning.';
    });
    Event::assertDispatchedTimes(DefinitionCreated::class, 1);
});

test('it does not broadcast an AI definition when the word is unpublished during generation', function () {
    Event::fake([DefinitionCreated::class]);
    $word = Word::factory()->create([
        'status' => 'available',
        'published_at' => now(),
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
    ]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()
        ->andReturnUsing(function () use ($word) {
            Word::query()->whereKey($word->id)->update(['published_at' => null]);

            return new GeneratedDefinition('A generated meaning.', 'noun', 'A blorg has a meaning.');
        });

    (new GenerateWordDefinition($word->id))->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    expect($word->fresh()->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeTrue();
    Event::assertNotDispatched(DefinitionCreated::class);
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

test('a failed duplicate job preserves a definition created by another worker', function () {
    $word = Word::factory()->create([
        'ai_definition_status' => Word::AI_DEFINITION_FAILED,
        'ai_definition_error' => 'Earlier failure',
    ]);
    $word->definitions()->create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'text' => 'A definition created by another worker.',
        'origin' => Definition::ORIGIN_AI,
        'part_of_speech' => 'noun',
    ]);

    (new GenerateWordDefinition($word->id))->failed(new DefinitionGenerationException('Gemini request failed.'));

    expect($word->fresh()->ai_definition_status)->toBe(Word::AI_DEFINITION_GENERATED)
        ->and($word->fresh()->ai_definition_error)->toBeNull();
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

    expect($complete->fresh()->ai_definition_status)->toBe(Word::AI_DEFINITION_GENERATED);
});

test('it supplies no exclusion context for the first batch slot', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $word = Word::factory()->create();
    WordBatchSlot::create([
        'word_batch_id' => $batch->id,
        'slot_number' => 1,
        'word_id' => $word->id,
        'status' => WordBatchSlot::PROCESSING,
    ]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()->with($word->text, [])
        ->andReturn(new GeneratedDefinition('A first batch meaning.', 'noun', 'The blorg started the batch.'));

    (new GenerateWordDefinition($word->id))->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    expect($word->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeTrue();
});

test('it defers a later batch slot until every earlier slot has an AI definition', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $earlierWord = Word::factory()->create();
    $laterWord = Word::factory()->create();
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 1, 'word_id' => $earlierWord->id, 'status' => WordBatchSlot::PROCESSING]);
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 2, 'word_id' => $laterWord->id, 'status' => WordBatchSlot::PROCESSING]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldNotReceive('generate');
    $queueJob = mock(\Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(5);
    $job = new GenerateWordDefinition($laterWord->id);
    $job->setJob($queueJob);

    $job->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    expect($laterWord->fresh()->ai_definition_status)->toBe(Word::AI_DEFINITION_PENDING)
        ->and($laterWord->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeFalse();
});

test('the database queue retries an out-of-order batch job after its predecessor completes', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $earlierWord = Word::factory()->create();
    $laterWord = Word::factory()->create();
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 1, 'word_id' => $earlierWord->id, 'status' => WordBatchSlot::PROCESSING]);
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 2, 'word_id' => $laterWord->id, 'status' => WordBatchSlot::PROCESSING]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()->with($earlierWord->text, [])
        ->ordered()
        ->andReturn(new GeneratedDefinition('A first queued meaning.', 'noun', 'The first blorg led the batch.'));
    $generator->shouldReceive('generate')->once()->with($laterWord->text, ['A first queued meaning.'])
        ->ordered()
        ->andReturn(new GeneratedDefinition('A second queued meaning.', 'verb', 'The second blorg followed the first.'));
    app()->instance(DefinitionGenerator::class, $generator);
    $queue = Queue::connection('database');
    $worker = app('queue.worker');
    $options = new WorkerOptions(maxTries: 0);

    $queue->push(new GenerateWordDefinition($laterWord->id));
    $worker->process('database', $queue->pop(), $options);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($laterWord->fresh()->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeFalse();

    $queue->push(new GenerateWordDefinition($earlierWord->id));
    $worker->process('database', $queue->pop(), $options);

    DB::table('jobs')->update(['available_at' => now()->timestamp]);
    $worker->process('database', $queue->pop(), $options);

    expect($earlierWord->fresh()->definitions()->where('origin', Definition::ORIGIN_AI)->value('text'))->toBe('A first queued meaning.')
        ->and($laterWord->fresh()->definitions()->where('origin', Definition::ORIGIN_AI)->value('text'))->toBe('A second queued meaning.')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('it gives a later batch slot its earlier AI definitions in slot order', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $firstWord = Word::factory()->create();
    $secondWord = Word::factory()->create();
    $laterWord = Word::factory()->create();
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 1, 'word_id' => $firstWord->id, 'status' => WordBatchSlot::PROCESSING]);
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 2, 'word_id' => $secondWord->id, 'status' => WordBatchSlot::PROCESSING]);
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 3, 'word_id' => $laterWord->id, 'status' => WordBatchSlot::PROCESSING]);
    $user = \App\Models\User::factory()->create();
    $firstWord->definitions()->create(['user_id' => $user->id, 'text' => 'The first distinct meaning.', 'origin' => Definition::ORIGIN_AI, 'part_of_speech' => 'noun']);
    $secondWord->definitions()->create(['user_id' => $user->id, 'text' => 'The second distinct meaning.', 'origin' => Definition::ORIGIN_AI, 'part_of_speech' => 'verb']);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()->with($laterWord->text, [
        'The first distinct meaning.',
        'The second distinct meaning.',
    ])->andReturn(new GeneratedDefinition('A third distinct meaning.', 'other', 'The blorg became a third thing.'));

    (new GenerateWordDefinition($laterWord->id))->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    expect($laterWord->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeTrue();
});

test('it defers when a predecessor slot is replaced during definition generation', function () {
    $batch = WordBatch::create(['scheduled_for' => now(), 'target_count' => WordBatch::SIZE]);
    $earlierWord = Word::factory()->create();
    $laterWord = Word::factory()->create();
    $earlierSlot = WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 1, 'word_id' => $earlierWord->id, 'status' => WordBatchSlot::PROCESSING]);
    WordBatchSlot::create(['word_batch_id' => $batch->id, 'slot_number' => 2, 'word_id' => $laterWord->id, 'status' => WordBatchSlot::PROCESSING]);
    $earlierWord->definitions()->create(['user_id' => \App\Models\User::factory()->create()->id, 'text' => 'A discarded predecessor meaning.', 'origin' => Definition::ORIGIN_AI, 'part_of_speech' => 'noun']);
    $replacement = Word::factory()->create();
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->once()->with($laterWord->text, ['A discarded predecessor meaning.'])
        ->andReturnUsing(function () use ($earlierSlot, $replacement) {
            $earlierSlot->update(['word_id' => $replacement->id]);

            return new GeneratedDefinition('A stale later meaning.', 'verb', 'The stale definition must not be saved.');
        });
    $queueJob = mock(\Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(5);
    $job = new GenerateWordDefinition($laterWord->id);
    $job->setJob($queueJob);

    $job->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));

    expect($laterWord->definitions()->where('origin', Definition::ORIGIN_AI)->exists())->toBeFalse();
});

test('it rate-limits with the registered definitions-ai limiter', function () {
    $rateLimited = collect((new GenerateWordDefinition(1))->middleware())
        ->first(fn ($middleware) => $middleware instanceof RateLimited);

    expect($rateLimited)->not->toBeNull();

    // RateLimited passes through untouched when its limiter name is unknown,
    // so the job's key must resolve to the definition AppServiceProvider registers.
    $limiterName = (new ReflectionProperty(RateLimited::class, 'limiterName'))->getValue($rateLimited);

    expect($limiterName)->toBe('definitions-ai')
        ->and(RateLimiter::limiter($limiterName))->not->toBeNull();
});

test('it defers jobs beyond the configured provider rate limit', function () {
    config()->set('services.definitions_ai.rate_limit', 2);
    RateLimiter::clear(md5('definitions-ai'));
    $middleware = new RateLimited('definitions-ai');
    $jobs = [new ReleasableJob, new ReleasableJob, new ReleasableJob];
    $handled = 0;

    foreach ($jobs as $job) {
        $middleware->handle($job, function () use (&$handled) {
            $handled++;
        });
    }

    expect($handled)->toBe(2)
        ->and($jobs[2]->releasedAfter)->toBeGreaterThan(0);
});

test('it drains a backfill across more rate-limit windows than provider retries', function () {
    config()->set('services.definitions_ai.rate_limit', 10);
    RateLimiter::clear(md5('definitions-ai'));
    $words = Word::factory()->count(31)->create();
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generate')->times(31)
        ->andReturn(new GeneratedDefinition('A backfilled definition.', 'noun', 'A blorg made the gathering brighter.'));
    app()->instance(DefinitionGenerator::class, $generator);

    $queue = Queue::connection('database');
    foreach ($words as $word) {
        $queue->push(new GenerateWordDefinition($word->id));
    }

    $worker = app('queue.worker');
    $options = new WorkerOptions(maxTries: 0);

    foreach (range(1, 4) as $window) {
        DB::table('jobs')->update(['available_at' => now()->timestamp]);

        while (($job = $queue->pop()) !== null) {
            $worker->process('database', $job, $options);
        }

        RateLimiter::clear(md5('definitions-ai'));
    }

    expect(Definition::where('origin', Definition::ORIGIN_AI)->count())->toBe(31)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('it defers a duplicate job while its word generation is in progress', function () {
    $word = Word::factory()->create();
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldNotReceive('generate');
    $job = new GenerateWordDefinition($word->id);
    $middleware = collect($job->middleware())
        ->first(fn ($middleware) => $middleware instanceof WithoutOverlapping);
    $queueJob = new ReleasableJob;
    $lock = Cache::lock($middleware->getLockKey($queueJob), $middleware->expiresAfter);

    expect($lock->get())->toBeTrue();

    try {
        $middleware->handle($queueJob, function () use ($job, $generator) {
            $job->handle($generator, app(\App\Services\Definitions\SystemAiUser::class));
        });
    } finally {
        $lock->release();
    }

    expect($queueJob->releasedAfter)->toBe(5);
});

class ReleasableJob
{
    public ?int $releasedAfter = null;

    public function release(int $delay): void
    {
        $this->releasedAfter = $delay;
    }
}
