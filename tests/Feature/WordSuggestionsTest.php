<?php

use App\Models\User;
use App\Models\Word;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Typeahead suggestions for the search bar.
 *
 * Given visitors type in the search bar,
 * When the frontend queries the suggestions endpoint,
 * Then they should get matching available words, never dictionary or owned words.
 */
function makeSuggestionWord(array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 2,
        'status' => 'available',
    ], $attrs));
}

describe('suggestions endpoint', function () {
    test('Given a matching word, it is returned with the expected shape', function () {
        makeSuggestionWord(['text' => 'blorg', 'slug' => 'blorg', 'syllables' => 2]);

        $this->getJson(route('words.suggestions', ['q' => 'blo']))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJson([
                ['id' => 1, 'text' => 'blorg', 'slug' => 'blorg', 'syllables' => 2],
            ]);
    });

    test('Given words, matching is case-insensitive', function () {
        makeSuggestionWord(['text' => 'BLORG', 'slug' => 'blorg']);

        $this->getJson(route('words.suggestions', ['q' => 'blo']))
            ->assertJsonCount(1)
            ->assertJson([['text' => 'BLORG']]);
    });

    test('Given words, matching is substring based', function () {
        makeSuggestionWord(['text' => 'zorp', 'slug' => 'zorp']);

        $this->getJson(route('words.suggestions', ['q' => 'orp']))
            ->assertJsonCount(1)
            ->assertJson([['text' => 'zorp']]);
    });

    test('Given a single-character query, an empty list is returned', function () {
        makeSuggestionWord(['text' => 'blorg', 'slug' => 'blorg']);

        $this->getJson(route('words.suggestions', ['q' => 'b']))
            ->assertOk()
            ->assertJsonCount(0);
    });

    test('Given no matching words, an empty list is returned', function () {
        makeSuggestionWord(['text' => 'blorg', 'slug' => 'blorg']);

        $this->getJson(route('words.suggestions', ['q' => 'zzzz']))
            ->assertOk()
            ->assertJsonCount(0);
    });

    test('Given a query containing LIKE wildcards, an empty list is returned', function () {
        makeSuggestionWord(['text' => 'bl%rg', 'slug' => 'blorg']);

        $this->getJson(route('words.suggestions', ['q' => 'bl%r']))
            ->assertOk()
            ->assertJsonCount(0);
    });

    test('Given an owned word, it is not suggested', function () {
        $user = User::factory()->create();
        makeSuggestionWord(['text' => 'ownedword', 'slug' => 'ownedword', 'status' => 'owned', 'owner_user_id' => $user->id]);

        $this->getJson(route('words.suggestions', ['q' => 'owned']))
            ->assertOk()
            ->assertJsonCount(0);
    });

    test('Given a dictionary-matched word, it is not suggested', function () {
        makeSuggestionWord(['text' => 'realword', 'slug' => 'realword', 'dictionary_status' => 'exists']);

        $this->getJson(route('words.suggestions', ['q' => 'real']))
            ->assertOk()
            ->assertJsonCount(0);
    });

    test('Given more than 8 matches, only 8 are returned', function () {
        foreach (['blor', 'bloa', 'blob', 'bloc', 'blod', 'bloe', 'blof', 'blog', 'bloh'] as $i => $text) {
            makeSuggestionWord(['text' => $text, 'slug' => $text]);
        }

        $this->getJson(route('words.suggestions', ['q' => 'blo']))
            ->assertOk()
            ->assertJsonCount(8);
    });
});
