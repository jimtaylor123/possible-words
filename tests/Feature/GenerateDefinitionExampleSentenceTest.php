<?php

use App\Contracts\DefinitionGenerator;
use App\Jobs\GenerateDefinitionExampleSentence;
use App\Models\Definition;
use App\Models\User;
use App\Models\Word;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\RateLimiter;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it backfills only a missing AI example sentence', function () {
    $definition = aiDefinition('A pleasant made-up thing.', 'noun');
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldReceive('generateExampleSentence')->once()
        ->with($definition->word->text, 'A pleasant made-up thing.', 'noun')
        ->andReturn('The blorg brought a smile to everyone.');

    (new GenerateDefinitionExampleSentence($definition->id))->handle($generator);

    expect($definition->fresh()->example_sentence)->toBe('The blorg brought a smile to everyone.')
        ->and($definition->fresh()->text)->toBe('A pleasant made-up thing.')
        ->and($definition->fresh()->part_of_speech)->toBe('noun');
});

test('it skips definitions that already have an example or are not AI generated', function () {
    $complete = aiDefinition('Established definition.', 'noun', 'An established example.');
    $human = aiDefinition('Human definition.', 'verb');
    $human->update(['origin' => null]);
    $generator = mock(DefinitionGenerator::class);
    $generator->shouldNotReceive('generateExampleSentence');

    (new GenerateDefinitionExampleSentence($complete->id))->handle($generator);
    (new GenerateDefinitionExampleSentence($human->id))->handle($generator);

    expect($complete->fresh()->example_sentence)->toBe('An established example.')
        ->and($human->fresh()->example_sentence)->toBeNull();
});

test('it uses the configured AI rate limit and a definition-specific lock', function () {
    $middleware = (new GenerateDefinitionExampleSentence(42))->middleware();
    $rateLimited = collect($middleware)->first(fn ($item) => $item instanceof RateLimited);
    $withoutOverlapping = collect($middleware)->first(fn ($item) => $item instanceof WithoutOverlapping);

    expect($rateLimited)->not->toBeNull()
        ->and(RateLimiter::limiter((new ReflectionProperty(RateLimited::class, 'limiterName'))->getValue($rateLimited)))->not->toBeNull()
        ->and($withoutOverlapping->getLockKey(new ReleasableExampleJob))->toContain('definitions-ai-example:42');
});

function aiDefinition(string $text, string $partOfSpeech, ?string $exampleSentence = null): Definition
{
    $word = Word::factory()->create();

    return $word->definitions()->create([
        'user_id' => User::factory()->create()->id,
        'text' => $text,
        'part_of_speech' => $partOfSpeech,
        'example_sentence' => $exampleSentence,
        'origin' => Definition::ORIGIN_AI,
    ]);
}

class ReleasableExampleJob
{
    public function release(int $delay): void {}
}
