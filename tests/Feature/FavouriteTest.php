<?php

use App\Models\Favourite;
use App\Models\User;
use App\Models\Word;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->word = Word::create([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 1,
        'status' => 'available',
    ]);
});

test('authenticated user can favourite a word', function () {
    $this->actingAs($this->user);

    $this->post(route('words.favourite', $this->word));

    $this->assertDatabaseHas('favourites', [
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);
});

test('authenticated user can unfavourite a word', function () {
    $this->actingAs($this->user);

    Favourite::create([
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);

    $this->post(route('words.favourite', $this->word));

    $this->assertDatabaseMissing('favourites', [
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);
});

test('guest cannot favourite a word', function () {
    $this->post(route('words.favourite', $this->word))
        ->assertStatus(302);
});

test('favourites page shows favourited words', function () {
    $this->actingAs($this->user);

    Favourite::create([
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);

    $otherWord = Word::create([
        'text' => 'zorp',
        'slug' => 'zorp',
        'syllables' => 1,
        'status' => 'available',
    ]);

    $this->get(route('words.favourites'))
        ->assertInertia(fn ($page) => $page
            ->component('Favourites/Index')
            ->has('words.data', 1)
            ->where('words.data.0.id', $this->word->id)
        );
});

test('favourites page is empty when user has no favourites', function () {
    $this->actingAs($this->user);

    $this->get(route('words.favourites'))
        ->assertInertia(fn ($page) => $page
            ->component('Favourites/Index')
            ->has('words.data', 0)
        );
});
