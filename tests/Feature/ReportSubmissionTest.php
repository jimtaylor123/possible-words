<?php

use App\Models\Report;
use App\Models\User;
use App\Models\Word;
use Illuminate\Database\QueryException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function reportableWord(): Word
{
    return Word::factory()->create();
}

function reportRoute(string $targetType, string|int $target): string
{
    return route('reports.store', ['targetType' => $targetType, 'target' => $target]);
}

describe('submitting reports', function () {
    test('an authenticated user can report a published word without an explanation', function () {
        $reporter = User::factory()->create();
        $word = reportableWord();

        $this->actingAs($reporter)
            ->post(reportRoute('word', $word->slug), [
                'reason' => Report::REASON_WORD_UNFRESH,
                'explanation' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Report submitted.');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'reportable_type' => Word::class,
            'reportable_id' => $word->id,
            'reason' => Report::REASON_WORD_UNFRESH,
            'explanation' => null,
            'status' => Report::STATUS_OPEN,
        ]);
    });

    test('an authenticated user can report a published word with the explanation field omitted entirely', function () {
        $reporter = User::factory()->create();
        $word = reportableWord();

        $this->actingAs($reporter)
            ->post(reportRoute('word', $word->slug), [
                'reason' => Report::REASON_WORD_UNFRESH,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Report submitted.');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'reportable_type' => Word::class,
            'reportable_id' => $word->id,
            'reason' => Report::REASON_WORD_UNFRESH,
            'explanation' => null,
        ]);
    });

    test('an authenticated user can report a live definition', function () {
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $definition = reportableWord()->definitions()->create([
            'user_id' => $author->id,
            'text' => 'An offensive definition.',
        ]);

        $this->actingAs($reporter)
            ->post(reportRoute('definition', $definition->id), [
                'reason' => Report::REASON_DEFINITION_OFFENSIVE,
                'explanation' => '',
            ])
            ->assertRedirect();

        expect($definition->reports()->first())
            ->reason->toBe(Report::REASON_DEFINITION_OFFENSIVE)
            ->explanation->toBeNull();
    });

    test('a reason must be supported by the target type', function () {
        $user = User::factory()->create();
        $word = reportableWord();

        $this->actingAs($user)
            ->post(reportRoute('word', $word->slug), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])
            ->assertSessionHasErrors('reason');

        expect(Report::count())->toBe(0);
    });

    test('the explanation is optional but cannot exceed 1000 characters', function () {
        $user = User::factory()->create();
        $word = reportableWord();

        $this->actingAs($user)
            ->post(reportRoute('word', $word->slug), [
                'reason' => Report::REASON_WORD_OFFENSIVE,
                'explanation' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors('explanation');

        expect(Report::count())->toBe(0);
    });

    test('report submission is rejected without a valid CSRF token', function () {
        $reporter = User::factory()->create();
        $word = reportableWord();

        // Feature tests normally bypass CSRF verification; force the
        // production behaviour so the token check actually runs.
        app()->instance('env', 'production');

        $this->actingAs($reporter)
            ->post(reportRoute('word', $word->slug), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertStatus(419);

        expect(Report::count())->toBe(0);
    });

    test('guests are redirected to sign in', function () {
        $word = reportableWord();

        $this->post(reportRoute('word', $word->slug), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertRedirect(route('auth.google'));
    });

    test('unknown target types and targets return not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(reportRoute('user', '1'), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertNotFound();

        $this->actingAs($user)
            ->post(reportRoute('definition', '999999'), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])
            ->assertNotFound();
    });

    test('unpublished words and removed definitions cannot be reported', function () {
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $unpublished = reportableWord();
        $unpublished->update(['published_at' => null]);
        $definition = reportableWord()->definitions()->create([
            'user_id' => $author->id,
            'text' => 'Removed definition.',
        ]);
        $definition->removed_at = now();
        $definition->save();

        $this->actingAs($reporter)
            ->post(reportRoute('word', $unpublished->slug), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertNotFound();

        $this->actingAs($reporter)
            ->post(reportRoute('definition', $definition->id), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])
            ->assertNotFound();
    });

    test('a definition whose word was withdrawn cannot be reported', function () {
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $word = reportableWord();
        $definition = $word->definitions()->create([
            'user_id' => $author->id,
            'text' => 'Definition on a withdrawn word.',
        ]);
        $word->delete();

        $this->actingAs($reporter)
            ->post(reportRoute('definition', $definition->id), ['reason' => Report::REASON_DEFINITION_OFFENSIVE])
            ->assertNotFound();

        expect(Report::count())->toBe(0);
    });

    test('different reporters may report the same target but one reporter may not duplicate an open report', function () {
        $firstReporter = User::factory()->create();
        $secondReporter = User::factory()->create();
        $word = reportableWord();

        $this->actingAs($firstReporter)
            ->post(reportRoute('word', $word->slug), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertRedirect();

        $this->actingAs($secondReporter)
            ->post(reportRoute('word', $word->slug), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertRedirect();

        $this->actingAs($firstReporter)
            ->post(reportRoute('word', $word->slug), ['reason' => Report::REASON_WORD_UNFRESH])
            ->assertRedirect()
            ->assertSessionHas('error', 'You have already reported this item.');

        expect(Report::count())->toBe(2);
    });

    test('the database prevents concurrent duplicate open reports', function () {
        $reporter = User::factory()->create();
        $word = reportableWord();

        $word->reports()->create([
            'reporter_id' => $reporter->id,
            'reason' => Report::REASON_WORD_UNFRESH,
            'status' => Report::STATUS_OPEN,
        ]);

        expect(fn () => $word->reports()->create([
            'reporter_id' => $reporter->id,
            'reason' => Report::REASON_WORD_OFFENSIVE,
            'status' => Report::STATUS_OPEN,
        ]))->toThrow(QueryException::class);
    });
});

describe('report relations and retention', function () {
    test('report relations retain records when a reporter or target is deleted', function () {
        $reporter = User::factory()->create();
        $word = reportableWord();
        $report = $word->reports()->create([
            'reporter_id' => $reporter->id,
            'reason' => Report::REASON_WORD_UNFRESH,
            'status' => Report::STATUS_OPEN,
        ]);

        expect($reporter->reports)->toHaveCount(1)
            ->and($word->reports)->toHaveCount(1);

        $reporter->delete();
        $word->forceDelete();

        expect($report->fresh())
            ->reporter_id->toBeNull()
            ->reportable_id->toBe($word->id)
            ->reportable_type->toBe(Word::class);
    });
});
