<?php

use App\Events\DefinitionCreated;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Facades\Event;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createWord(): Word
{
    return Word::create([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 1,
        'status' => 'available',
    ]);
}

describe('adding a definition', function () {
    test('Given a logged-in user, they can add a definition and it is auto-liked', function () {
        Event::fake([DefinitionCreated::class]);
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), ['text' => 'A made-up meaning'])
            ->assertRedirect(route('words.show', $word));

        $definition = $word->definitions()->first();
        expect($definition)->not->toBeNull();
        expect($definition->text)->toBe('A made-up meaning');
        expect($definition->user_id)->toBe($user->id);
        expect($definition->votes_count)->toBe(1);
        expect($definition->votes()->where('user_id', $user->id)->exists())->toBeTrue();

        Event::assertDispatched(DefinitionCreated::class);
    });

    test('Given an empty text, the definition is rejected', function () {
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), ['text' => ''])
            ->assertSessionHasErrors('text');
    });

    test('Given exactly 1000 chars, the definition is accepted', function () {
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), ['text' => str_repeat('a', 1000)])
            ->assertRedirect(route('words.show', $word));

        expect($word->definitions()->first()->text)->toHaveLength(1000);
    });

    test('Given a text over 1000 chars, the definition is rejected', function () {
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), ['text' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('text');
    });

    test('Given a guest, adding a definition redirects to login', function () {
        $word = createWord();

        $this->post(route('words.definitions.store', $word), ['text' => 'nope'])
            ->assertRedirect();
    });
});

describe('liking a definition', function () {
    test('Given a definition, a user can like it and the count increments', function () {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'base', 'votes_count' => 0]);

        $this->actingAs($liker)
            ->post(route('definitions.vote', $definition))
            ->assertRedirect();

        expect($definition->fresh()->votes_count)->toBe(1);
        expect($definition->votes()->where('user_id', $liker->id)->exists())->toBeTrue();
    });

    test('Given a user who already liked, liking again unlikes and decrements', function () {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'base', 'votes_count' => 1]);
        $definition->votes()->create(['user_id' => $liker->id]);

        $this->actingAs($liker)
            ->post(route('definitions.vote', $definition))
            ->assertRedirect();

        expect($definition->fresh()->votes_count)->toBe(0);
        expect($definition->votes()->where('user_id', $liker->id)->exists())->toBeFalse();
    });

    test('Given a concurrent double-like race, the unique constraint rejects the duplicate vote', function () {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'base', 'votes_count' => 0]);
        $definition->votes()->create(['user_id' => $liker->id]);

        // A double-submit before the first request commits would insert twice;
        // the second insert must be rejected by the unique constraint.
        expect(fn () => $definition->votes()->create(['user_id' => $liker->id]))
            ->toThrow(\Illuminate\Database\QueryException::class);

        expect($definition->votes()->where('user_id', $liker->id)->count())->toBe(1);
    });

    test('Given a definition the author auto-liked, a second user liking it increments the count to two', function () {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();

        $this->actingAs($author)
            ->post(route('words.definitions.store', $word), ['text' => 'auto-liked'])
            ->assertRedirect();

        $definition = $word->definitions()->first();
        expect($definition->votes_count)->toBe(1);

        $this->actingAs($liker)
            ->post(route('definitions.vote', $definition))
            ->assertRedirect();

        expect($definition->fresh()->votes_count)->toBe(2);
    });

    test('Given a guest, liking redirects to login', function () {
        $author = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'base', 'votes_count' => 0]);

        $this->post(route('definitions.vote', $definition))
            ->assertRedirect();
    });
});

describe('favouriting', function () {
    test('Given a logged-in user, they can favourite a word and see it on the favourites page', function () {
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.favourite', $word))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('words.favourites'))
            ->assertInertia(fn ($page) => $page
                ->component('Favourites/Index')
                ->has('words.data', 1)
                ->where('words.data.0.id', $word->id)
            );
    });

    test('Given a user who favourited, toggling removes it from favourites', function () {
        $user = User::factory()->create();
        $word = createWord();
        \App\Models\Favourite::create(['user_id' => $user->id, 'word_id' => $word->id]);

        $this->actingAs($user)
            ->post(route('words.favourite', $word))
            ->assertRedirect();

        expect(\App\Models\Favourite::where('user_id', $user->id)->where('word_id', $word->id)->exists())->toBeFalse();
    });

    test('Given a user with no favourites, the favourites page shows an empty list', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('words.favourites'))
            ->assertInertia(fn ($page) => $page
                ->component('Favourites/Index')
                ->has('words.data', 0)
            );
    });

    test('Given a guest, favouriting redirects to login', function () {
        $word = createWord();

        $this->post(route('words.favourite', $word))->assertRedirect();
    });

    test('Given a guest, the favourites page redirects to login', function () {
        $this->get(route('words.favourites'))->assertRedirect();
    });
});

describe('definition integrity', function () {
    test('Given an unknown definition, voting returns 404', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/definitions/999999/vote')
            ->assertStatus(404);
    });
});
