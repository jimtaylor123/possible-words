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
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
        'published_at' => now(),
    ]);
}

describe('adding a definition', function () {
    test('Given an unreleased word, adding a definition returns 404', function () {
        $user = User::factory()->create();
        $word = createWord();
        $word->update(['published_at' => null]);

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), ['text' => 'Hidden meaning'])
            ->assertNotFound();
    });

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

    test('Given a selected category, it is persisted and broadcast with the definition', function (string $partOfSpeech) {
        Event::fake([DefinitionCreated::class]);
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), [
                'text' => 'A categorized meaning',
                'part_of_speech' => $partOfSpeech,
            ])
            ->assertRedirect(route('words.show', $word));

        $definition = $word->definitions()->first();
        expect($definition->part_of_speech)->toBe($partOfSpeech);

        Event::assertDispatched(DefinitionCreated::class, function (DefinitionCreated $event) use ($partOfSpeech) {
            return $event->definition->part_of_speech === $partOfSpeech;
        });
    })->with(['noun', 'verb', 'other']);

    test('Given no category, the definition stores an uncategorized null value', function () {
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), ['text' => 'An uncategorized meaning'])
            ->assertRedirect(route('words.show', $word));

        expect($word->definitions()->first()->part_of_speech)->toBeNull();
    });

    test('Given an invalid category, the definition is rejected without creating a row', function (string $partOfSpeech) {
        $user = User::factory()->create();
        $word = createWord();

        $this->actingAs($user)
            ->post(route('words.definitions.store', $word), [
                'text' => 'An invalid categorized meaning',
                'part_of_speech' => $partOfSpeech,
            ])
            ->assertSessionHasErrors('part_of_speech');

        expect($word->definitions()->count())->toBe(0);
    })->with(['adjective', 'Noun']);

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
    test('Given an unreleased word, liking its definition returns 404 without voting', function () {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();
        $word->update(['published_at' => null]);
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'hidden', 'votes_count' => 0]);

        $this->actingAs($liker)
            ->post(route('definitions.vote', $definition))
            ->assertNotFound();

        expect($definition->fresh()->votes_count)->toBe(0)
            ->and($definition->votes()->exists())->toBeFalse();
    });

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
    test('Given an unreleased word, it cannot be favourited or listed', function () {
        $user = User::factory()->create();
        $word = createWord();
        $word->update(['published_at' => null]);

        $this->actingAs($user)
            ->post(route('words.favourite', $word))
            ->assertNotFound();

        \App\Models\Favourite::create(['user_id' => $user->id, 'word_id' => $word->id]);

        $this->actingAs($user)
            ->get(route('words.favourites'))
            ->assertInertia(fn ($page) => $page->has('words.data', 0));
    });

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

describe('removing a definition', function () {
    test('Given an unreleased word, removing its definition returns 404 without removing it', function () {
        $author = User::factory()->create();
        $word = createWord();
        $word->update(['published_at' => null]);
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'hidden']);

        $this->actingAs($author)
            ->post(route('definitions.remove', $definition))
            ->assertNotFound();

        expect($definition->fresh()->removed_at)->toBeNull();
    });

    test('Given the author, removing their definition sets removed_at in the database', function () {
        Event::fake([DefinitionCreated::class]);
        $author = User::factory()->create();
        $word = createWord();

        $this->actingAs($author)
            ->post(route('words.definitions.store', $word), ['text' => 'A made-up meaning'])
            ->assertRedirect(route('words.show', $word));

        $definition = $word->definitions()->first();
        expect($definition->removed_at)->toBeNull();

        $this->actingAs($author)
            ->post(route('definitions.remove', $definition))
            ->assertRedirect();

        // Assert the column, not just the status: removed_at is deliberately not
        // fillable, so a mass-assigned update() would 302 while writing nothing.
        $fresh = $definition->fresh();
        expect($fresh->removed_at)->not->toBeNull();
        expect($fresh->isRemoved())->toBeTrue();

        // Retained rather than destroyed: neither the text nor the count moves.
        expect($fresh->text)->toBe('A made-up meaning');
        expect($fresh->votes_count)->toBe(1);
    });

    test('Given a non-author, removing the definition is forbidden and changes nothing', function () {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'mine', 'votes_count' => 3]);

        $this->actingAs($stranger)
            ->post(route('definitions.remove', $definition))
            ->assertForbidden();

        expect($definition->fresh()->removed_at)->toBeNull();
        expect($definition->fresh()->votes_count)->toBe(3);
    });

    test('Given a guest, removing a definition redirects to login', function () {
        $author = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'mine']);

        $this->post(route('definitions.remove', $definition))
            ->assertRedirect(route('auth.google'));

        expect($definition->fresh()->removed_at)->toBeNull();
    });

    test('Given a definition already removed, removing it again is a no-op', function () {
        $author = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'mine', 'votes_count' => 2]);
        $definition->votes()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();
        $firstRemoval = $definition->fresh()->removed_at;

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        $afterSecond = $definition->fresh();
        expect($afterSecond->removed_at->toDateTimeString())->toBe($firstRemoval->toDateTimeString());
        expect($afterSecond->votes_count)->toBe(2);
    });

    test('Given a liked definition, removal preserves the count and the vote rows', function () {
        Event::fake([DefinitionCreated::class]);
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();

        // storeDefinition() auto-likes on the author's behalf, so the definition starts
        // at one like before anyone else touches it.
        $this->actingAs($author)
            ->post(route('words.definitions.store', $word), ['text' => 'auto-liked'])
            ->assertRedirect();

        $definition = $word->definitions()->first();
        $this->actingAs($liker)->post(route('definitions.vote', $definition))->assertRedirect();
        expect($definition->fresh()->votes_count)->toBe(2);

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        $removed = $definition->fresh();
        expect($removed->removed_at)->not->toBeNull();
        expect($removed->votes_count)->toBe(2);
        expect($removed->votes()->count())->toBe(2);
        expect($removed->votes()->where('user_id', $author->id)->exists())->toBeTrue();
        expect($removed->votes()->where('user_id', $liker->id)->exists())->toBeTrue();
    });

    test('Given a removed definition, a third party voting on it changes nothing', function () {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $word = createWord();

        // storeDefinition() auto-likes on the author's behalf, so the definition starts
        // at one like before anyone else touches it.
        $this->actingAs($author)
            ->post(route('words.definitions.store', $word), ['text' => 'auto-liked'])
            ->assertRedirect();

        $definition = $word->definitions()->first();
        expect($definition->fresh()->votes_count)->toBe(1);

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        // A removed definition is inert, and the endpoint has to enforce that on its
        // own: Show.vue hides the vote button, but without a server-side guard a
        // crafted POST would still add a vote row and bump the [deleted] card's count
        // for every other viewer watching over the broadcast.
        $this->actingAs($liker)
            ->post(route('definitions.vote', $definition))
            ->assertRedirect();

        $fresh = $definition->fresh();
        expect($fresh->removed_at)->not->toBeNull();
        expect($fresh->isRemoved())->toBeTrue();
        expect($fresh->votes_count)->toBe(1);
        expect($fresh->votes()->count())->toBe(1);
        expect($fresh->votes()->where('user_id', $liker->id)->exists())->toBeFalse();
    });

    test('Given a removed definition, removed_at is cast to a Carbon instance', function () {
        $author = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'mine']);

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        // Guards against a `$dates` declaration, which Laravel 12 ignores silently:
        // the value would still be truthy as a raw string, so only the type catches it.
        expect($definition->fresh()->removed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    test('Given a removed definition, the database text survives untouched', function () {
        $author = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'the original meaning']);

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        // toArray() is where the redaction lives, so the model's own attribute still
        // reads the original — proving nothing is destroyed on the way out.
        $fresh = $definition->fresh();
        expect($fresh->text)->toBe('the original meaning');
        expect($fresh->toArray()['text'])->toBe('[deleted]');

        // And because the substitution is not on the attribute itself, saving the
        // instance cannot write the placeholder back into the row. This is the
        // difference between overriding toArray() and adding a text accessor.
        $fresh->save();
        expect($definition->fresh()->text)->toBe('the original meaning');
    });
});

describe('a removed definition on the word page', function () {
    test('Given a removed definition, the page still lists it as a deleted card with its count', function () {
        Event::fake([DefinitionCreated::class]);
        $author = User::factory()->create();
        $word = createWord();

        $this->actingAs($author)
            ->post(route('words.definitions.store', $word), ['text' => 'A made-up meaning'])
            ->assertRedirect();

        $definition = $word->definitions()->first();
        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        $this->get(route('words.show', $word))
            ->assertInertia(fn ($page) => $page
                ->component('Words/Show')
                ->has('word.definitions', 1)
                ->where('word.definitions.0.id', $definition->id)
                // The placeholder is the server's substituted string, so the client
                // can branch on removed_at without any string being duplicated in JS.
                ->where('word.definitions.0.text', '[deleted]')
                ->where('word.definitions.0.votes_count', 1)
                ->where('word.definitions.0.removed_at', fn ($value) => $value !== null)
            );
    });

    test('Given a word whose only definition is removed, the list is not empty', function () {
        $author = User::factory()->create();
        $word = createWord();
        $definition = $word->definitions()->create(['user_id' => $author->id, 'text' => 'mine']);

        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();

        // The "no definitions yet" empty state is for a word that never had one; a
        // removed definition keeps its [deleted] card, so the list stays non-empty.
        $this->get(route('words.show', $word))
            ->assertInertia(fn ($page) => $page->has('word.definitions', 1));
    });

    test('Given a word with no definitions at all, the empty state still applies', function () {
        $word = createWord();

        $this->get(route('words.show', $word))
            ->assertInertia(fn ($page) => $page->has('word.definitions', 0));
    });

    test('Given a removed definition, its text is absent from every page that quotes one', function () {
        Event::fake([DefinitionCreated::class]);
        $author = User::factory()->create();
        $word = createWord();
        $secret = 'ORIGINAL-TEXT-MUST-NOT-LEAK-4f21';

        $this->actingAs($author)
            ->post(route('words.definitions.store', $word), ['text' => $secret])
            ->assertRedirect();

        $definition = $word->definitions()->first();
        $this->actingAs($author)->post(route('definitions.remove', $definition))->assertRedirect();
        $this->actingAs($author)->post(route('words.favourite', $word))->assertRedirect();

        // All three server-to-client paths, not just show(): the browse card and the
        // favourites card both quote word.definitions[0].text verbatim. The text is
        // checked against the raw body because Inertia embeds the whole page payload in
        // the HTML — painting over it in the template would not remove it from here.
        $this->get(route('words.show', $word))->assertDontSee($secret, false);
        $this->get(route('home'))->assertDontSee($secret, false);
        $this->get(route('words.favourites'))->assertDontSee($secret, false);
    });
});

describe('definition categories on the word page', function () {
    test('Given categorized and uncategorized definitions, both category values are serialized', function () {
        $author = User::factory()->create();
        $word = createWord();
        $categorized = $word->definitions()->create([
            'user_id' => $author->id,
            'text' => 'A noun meaning',
            'part_of_speech' => 'noun',
            'votes_count' => 1,
        ]);
        $uncategorized = $word->definitions()->create([
            'user_id' => $author->id,
            'text' => 'An uncategorized meaning',
        ]);

        $this->get(route('words.show', $word))
            ->assertInertia(fn ($page) => $page
                ->component('Words/Show')
                ->where('word.definitions.0.id', $categorized->id)
                ->where('word.definitions.0.part_of_speech', 'noun')
                ->where('word.definitions.1.id', $uncategorized->id)
                ->where('word.definitions.1.part_of_speech', null)
            );
    });

    test('Given an AI definition, its provenance and system author are serialized', function () {
        $word = createWord();
        $word->update(['ai_definition_error' => 'Internal provider detail']);
        $author = User::factory()->create(['name' => 'PossibleWords AI']);
        $definition = $word->definitions()->create([
            'user_id' => $author->id,
            'text' => 'An invented AI meaning',
            'part_of_speech' => 'verb',
            'origin' => \App\Models\Definition::ORIGIN_AI,
        ]);

        $this->get(route('words.show', $word))
            ->assertInertia(fn ($page) => $page
                ->where('word.definitions.0.id', $definition->id)
                ->where('word.definitions.0.origin', 'ai')
                ->where('word.definitions.0.user.name', 'PossibleWords AI')
                ->missing('word.ai_definition_error')
            );
    });

    test('Given an AI definition, even its system author cannot remove it', function () {
        $author = User::factory()->create(['name' => 'PossibleWords AI']);
        $word = createWord();
        $definition = $word->definitions()->create([
            'user_id' => $author->id,
            'text' => 'Protected AI meaning',
            'origin' => \App\Models\Definition::ORIGIN_AI,
            'part_of_speech' => 'other',
        ]);

        $this->actingAs($author)
            ->post(route('definitions.remove', $definition))
            ->assertForbidden();

        expect($definition->fresh()->removed_at)->toBeNull();
    });
});
