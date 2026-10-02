<?php

use App\Models\Word;
use App\Services\DictionaryService;
use Illuminate\Support\Facades\Queue;

/**
 * Reversible withdrawal from the public site.
 *
 * Given a word turns out to be a real word (or was withdrawn by mistake),
 * When it is withdrawn with words:withdraw,
 * Then it must disappear from the browse list, search and its own page,
 * And words:restore must bring it back.
 */
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $dictionary = mock(DictionaryService::class);
    $dictionary->shouldReceive('isInBannedList')->andReturn(false);
    $this->app->instance(DictionaryService::class, $dictionary);
});

function withdrawableWord(array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 1,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
    ], $attrs));
}

describe('previewing a withdrawal', function () {
    test('Given --dry-run, nothing is withdrawn', function () {
        $word = withdrawableWord();

        $this->artisan('words:withdraw --text='.$word->text.' --dry-run')
            ->assertExitCode(0);

        expect($word->fresh()->trashed())->toBeFalse();
    });

    test('Given no confirmation, nothing is withdrawn and the command says so', function () {
        $word = withdrawableWord();

        $this->artisan('words:withdraw --text='.$word->text)
            ->expectsOutputToContain('--confirmed')
            ->assertExitCode(0);

        expect($word->fresh()->trashed())->toBeFalse();
    });

    test('Given nothing to withdraw, the command says so', function () {
        $this->artisan('words:withdraw --text=nothinglikethis')
            ->expectsOutputToContain('No words to withdraw')
            ->assertExitCode(0);
    });
});

describe('withdrawing a word', function () {
    test('Given --confirmed, the word is soft deleted', function () {
        $word = withdrawableWord();

        $this->artisan('words:withdraw --text='.$word->text.' --confirmed')
            ->assertExitCode(0);

        $word->refresh();

        expect($word->trashed())->toBeTrue();
        expect($word->deleted_at)->not->toBeNull();
        expect(Word::withTrashed()->find($word->id))->not->toBeNull();
    });

    test('Given a withdrawn word, it is absent from the browse list and its page 404s', function () {
        $word = withdrawableWord(['text' => 'withdrawnword', 'slug' => 'withdrawnword']);

        $this->artisan('words:withdraw --text=withdrawnword --confirmed')
            ->assertExitCode(0);

        $this->get(route('home'))
            ->assertInertia(fn ($page) => $page->has('words.data', 0));

        $this->getJson(route('words.suggestions', ['q' => 'withdrawn']))
            ->assertJsonCount(0);

        $this->get(route('words.show', $word))->assertStatus(404);
    });

    test('Given a confirmed real word and no selector, it is withdrawn by status', function () {
        $real = withdrawableWord([
            'text' => 'realword',
            'slug' => 'realword',
            'dictionary_status' => Word::DICTIONARY_EXISTS_AS_WORD,
        ]);
        $safe = withdrawableWord(['text' => 'safeword', 'slug' => 'safeword']);

        $this->artisan('words:withdraw --confirmed')
            ->assertExitCode(0);

        expect($real->fresh()->trashed())->toBeTrue();
        expect($safe->fresh()->trashed())->toBeFalse();
    });

    test('Given an already-withdrawn word, it is not counted again', function () {
        $word = withdrawableWord();

        $this->artisan('words:withdraw --text='.$word->text.' --confirmed')
            ->assertExitCode(0);

        $this->artisan('words:withdraw --text='.$word->text.' --confirmed')
            ->expectsOutputToContain('No words to withdraw')
            ->assertExitCode(0);
    });
});

describe('restoring a withdrawn word', function () {
    test('Given a withdrawn word, words:restore brings it back', function () {
        $word = withdrawableWord(['text' => 'backword', 'slug' => 'backword']);

        $this->artisan('words:withdraw --text=backword --confirmed')->assertExitCode(0);

        $this->artisan('words:restore --text=backword')->assertExitCode(0);

        expect($word->fresh()->trashed())->toBeFalse();

        $this->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'backword')
            );
    });

    test('Given no selector, words:restore reports the withdrawn count and changes nothing', function () {
        $word = withdrawableWord();

        $this->artisan('words:withdraw --text='.$word->text.' --confirmed')->assertExitCode(0);

        $this->artisan('words:restore')
            ->expectsOutputToContain('1 withdrawn word(s)')
            ->assertExitCode(0);

        expect($word->fresh()->trashed())->toBeTrue();
    });

    test('Given nothing to restore, the command says so', function () {
        $this->artisan('words:restore --all')
            ->expectsOutputToContain('Nothing to restore')
            ->assertExitCode(0);
    });
});

describe('generating over a withdrawn word', function () {
    test('Given a withdrawn word, words:generate does not re-create it', function () {
        $word = withdrawableWord();

        $this->artisan('words:withdraw --text='.$word->text.' --confirmed')->assertExitCode(0);

        $this->artisan('words:generate --count=5 --fast')->assertExitCode(0);

        // The trashed row is untouched and no duplicate row was inserted.
        expect(Word::withTrashed()->count())->toBe(6);
        expect(Word::withTrashed()->where('text', $word->text)->count())->toBe(1);
        expect(Word::withTrashed()->find($word->id)->trashed())->toBeTrue();
    });
});
