<?php

use App\Contracts\DefinitionGenerator;
use App\Data\GeneratedDefinition;
use App\Exceptions\DefinitionGenerationException;
use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

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
        ->andReturn(new GeneratedDefinition('A backfilled definition.', 'noun'));
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
