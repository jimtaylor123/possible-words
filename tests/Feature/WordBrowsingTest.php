<?php

use App\Models\User;
use App\Models\Word;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Browsing the catalogue.
 *
 * Given visitors open the site,
 * When they browse and filter the word list,
 * Then they should only ever see available, non-dictionary words.
 */
function makeWord(array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 2,
        'status' => 'available',
    ], $attrs));
}

describe('browsing the catalogue', function () {
    test('Given published words, the home page lists them as Inertia props', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->has('words.data', 1)
                ->where('words.data.0.text', 'blorg')
            );
    });

    test('Given an owned word, it is hidden from the public list', function () {
        $user = User::factory()->create();
        makeWord(['text' => 'ownedword', 'slug' => 'ownedword', 'status' => 'owned', 'owner_user_id' => $user->id]);

        $this->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 0)
            );
    });

    test('Given a word matched by the dictionary, it is hidden from the public list', function () {
        makeWord(['text' => 'realword', 'slug' => 'realword', 'dictionary_status' => 'exists']);

        $this->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 0)
            );
    });
});

describe('searching', function () {
    test('Given words, searching by text returns only matching words', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);
        makeWord(['text' => 'zorp', 'slug' => 'zorp']);

        $this->get(route('words.index', ['search' => 'blor']))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'blorg')
                ->where('filters.search', 'blor')
            );
    });

    test('Given no matches, search returns an empty list', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);

        $this->get(route('words.index', ['search' => 'zzzz']))
            ->assertInertia(fn ($page) => $page->has('words.data', 0));
    });
});

describe('filtering by syllables', function () {
    test('Given a word with 2 syllables, filtering by 2 returns it', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg', 'syllables' => 2]);

        $this->get(route('words.index', ['syllables' => 2]))
            ->assertInertia(fn ($page) => $page->has('words.data', 1));
    });

    test('Given a threshold of 5+, filtering by 5 includes all longer words', function () {
        makeWord(['text' => 'short', 'slug' => 'short', 'syllables' => 3]);
        makeWord(['text' => 'longer', 'slug' => 'longer', 'syllables' => 5]);

        $this->get(route('words.index', ['syllables' => 5]))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'longer')
            );
    });
});

describe('filtering by length', function () {
    test('Given words, filtering by length returns only that length', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);
        makeWord(['text' => 'zorp', 'slug' => 'zorp']);

        $this->get(route('words.index', ['length' => 4]))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'zorp')
            );
    });

    test('Given a threshold of 8+, filtering by 8 includes all longer words', function () {
        makeWord(['text' => 'seven', 'slug' => 'seven']);
        makeWord(['text' => 'eightchar', 'slug' => 'eightchar']);

        $this->get(route('words.index', ['length' => 8]))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'eightchar')
            );
    });
});

describe('filtering by first letter', function () {
    test('Given words, filtering by starts_with returns matching words', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);
        makeWord(['text' => 'zorp', 'slug' => 'zorp']);

        $this->get(route('words.index', ['starts_with' => 'b']))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'blorg')
            );
    });
});

describe('sorting', function () {
    test('Given words, sorting alphabetically by name with direction asc', function () {
        makeWord(['text' => 'zorp', 'slug' => 'zorp']);
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);

        $this->get(route('words.index', ['sort' => 'alphabetical', 'direction' => 'asc']))
            ->assertInertia(fn ($page) => $page
                ->where('words.data.0.text', 'blorg')
                ->where('words.data.1.text', 'zorp')
            );
    });

    test('Given words, sorting by popularity orders by most-voted definition', function () {
        $popular = makeWord(['text' => 'popular', 'slug' => 'popular']);
        $quiet = makeWord(['text' => 'quiet', 'slug' => 'quiet']);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $popular->definitions()->create(['user_id' => $userA->id, 'text' => 'a', 'votes_count' => 3]);
        $quiet->definitions()->create(['user_id' => $userA->id, 'text' => 'a', 'votes_count' => 1]);

        $this->get(route('words.index', ['sort' => 'popularity']))
            ->assertInertia(fn ($page) => $page
                ->where('words.data.0.text', 'popular')
                ->where('words.data.1.text', 'quiet')
            );
    });

    test('Given words, sorting by fewest letters works with direction asc', function () {
        makeWord(['text' => 'blorg', 'slug' => 'blorg']);
        makeWord(['text' => 'zorp', 'slug' => 'zorp']);

        $this->get(route('words.index', ['sort' => 'letters', 'direction' => 'asc']))
            ->assertInertia(fn ($page) => $page
                ->where('words.data.0.text', 'zorp')
                ->where('words.data.1.text', 'blorg')
            );
    });
});

describe('pagination', function () {
    test('Given more than 20 words, the list paginates', function () {
        for ($i = 0; $i < 21; $i++) {
            makeWord(['text' => 'word'.$i, 'slug' => 'word'.$i]);
        }

        $this->get(route('words.index'))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 20)
                ->where('words.last_page', 2)
                ->where('words.current_page', 1)
            );
    });

    test('Given multiple pages, page 2 returns the remaining words', function () {
        for ($i = 0; $i < 21; $i++) {
            makeWord(['text' => 'word'.$i, 'slug' => 'word'.$i]);
        }

        $this->get(route('words.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.current_page', 2)
            );
    });
});

describe('word detail', function () {
    test('Given a word with definitions, its show page orders them by votes', function () {
        $word = makeWord();
        $user = User::factory()->create();
        $low = $word->definitions()->create(['user_id' => $user->id, 'text' => 'low', 'votes_count' => 1]);
        $high = $word->definitions()->create(['user_id' => $user->id, 'text' => 'high', 'votes_count' => 5]);

        $this->get(route('words.show', $word))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Words/Show')
                ->has('word.definitions', 2)
                ->where('word.definitions.0.id', $high->id)
                ->where('word.definitions.1.id', $low->id)
            );
    });

    test('Given an unknown slug, the show page returns 404', function () {
        $this->get('/words/does-not-exist')->assertStatus(404);
    });
});

describe('definition list integrity', function () {
    test('Given definitions, they belong to their word and user', function () {
        $word = makeWord();
        $user = User::factory()->create();
        $def = $word->definitions()->create(['user_id' => $user->id, 'text' => 'hello']);

        expect($def->word->id)->toBe($word->id);
        expect($def->user->id)->toBe($user->id);
    });
});
