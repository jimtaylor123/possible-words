<?php

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

test('definition is automatically upvoted by the author', function () {
    $this->actingAs($this->user);

    $this->post(route('words.definitions.store', $this->word), [
        'text' => 'A test definition',
    ]);

    $definition = $this->word->definitions()->first();

    expect($definition)->not->toBeNull();
    expect($definition->votes_count)->toBe(1);

    $vote = $definition->votes()->where('user_id', $this->user->id)->first();
    expect($vote)->not->toBeNull();
    expect($vote->value)->toBe(1);
});

test('user cannot downvote their own definition', function () {
    $this->actingAs($this->user);

    $definition = $this->word->definitions()->create([
        'user_id' => $this->user->id,
        'text' => 'My own definition',
    ]);

    $this->post(route('definitions.vote', $definition), [
        'value' => -1,
    ]);

    expect($definition->fresh()->votes_count)->toBe(0);

    $vote = $definition->votes()->where('user_id', $this->user->id)->first();
    expect($vote)->toBeNull();
});

test('user cannot upvote their own definition', function () {
    $this->actingAs($this->user);

    $definition = $this->word->definitions()->create([
        'user_id' => $this->user->id,
        'text' => 'My own definition',
    ]);

    $this->post(route('definitions.vote', $definition), [
        'value' => 1,
    ]);

    expect($definition->fresh()->votes_count)->toBe(0);

    $vote = $definition->votes()->where('user_id', $this->user->id)->first();
    expect($vote)->toBeNull();
});

test('user can vote on another users definition', function () {
    $otherUser = User::factory()->create();

    $definition = $this->word->definitions()->create([
        'user_id' => $otherUser->id,
        'text' => 'Another user definition',
    ]);

    $this->actingAs($this->user);
    $this->post(route('definitions.vote', $definition), [
        'value' => 1,
    ]);

    expect($definition->fresh()->votes_count)->toBe(1);

    $vote = $definition->votes()->where('user_id', $this->user->id)->first();
    expect($vote)->not->toBeNull();
    expect($vote->value)->toBe(1);
});

test('auto upvote is counted in votes_count', function () {
    $this->actingAs($this->user);

    $this->post(route('words.definitions.store', $this->word), [
        'text' => 'Auto-upvoted definition',
    ]);

    $definition = $this->word->definitions()->first();
    expect($definition->votes_count)->toBe(1);

    $otherUser = User::factory()->create();
    $this->actingAs($otherUser);
    $this->post(route('definitions.vote', $definition), [
        'value' => 1,
    ]);

    expect($definition->fresh()->votes_count)->toBe(2);
});
