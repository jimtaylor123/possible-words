<?php

use App\Models\Word;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Sorting the word list.
 *
 * Given a visitor sorts or filters the catalogue,
 * When they supply sort or direction values,
 * Then only known sorts and directions are honoured, and injected SQL is ignored rather than executed.
 */
function sortingWord(string $text, int $syllables = 2): Word
{
    return Word::create([
        'text' => $text,
        'slug' => $text,
        'syllables' => $syllables,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
        'published_at' => now(),
    ]);
}

function textsOnPage(object $response): array
{
    $texts = $response->viewData('page')['props']['words']['data'] ?? [];

    return array_column($texts, 'text');
}

describe('sorting the word list', function () {
    beforeEach(function () {
        sortingWord('zip');
        sortingWord('zic');
        sortingWord('moc');
        sortingWord('quuxly');
    });

    test('Given an injected direction, the raw SQL is ignored and sorting falls back to descending', function () {
        $injected = 'asc,(SELECT CASE WHEN (SELECT COUNT(*) FROM words)>0 THEN 1 ELSE 0 END)';

        $response = $this->get(route('words.index', ['sort' => 'letters', 'direction' => $injected]));

        $response->assertStatus(200);

        // Falls back to "desc", i.e. longest first, exactly as a plain desc request does.
        $expected = textsOnPage($this->get(route('words.index', ['sort' => 'letters', 'direction' => 'desc'])));

        expect(textsOnPage($response))->toBe($expected)
            ->and(textsOnPage($response)[0])->toBe('quuxly');
    });

    test('Given a garbage direction, the request succeeds instead of raising a SQL error', function () {
        $this->get(route('words.index', ['sort' => 'letters', 'direction' => 'zzzz']))
            ->assertStatus(200);

        $this->get(route('words.index', ['sort' => 'letters', 'direction' => "asc' OR 1=1 --"]))
            ->assertStatus(200);
    });

    test('Given valid directions, length sorting still works in both directions', function () {
        expect(textsOnPage($this->get(route('words.index', ['sort' => 'letters', 'direction' => 'asc'])))[0])
            ->toBe('zip');

        expect(textsOnPage($this->get(route('words.index', ['sort' => 'letters', 'direction' => 'desc'])))[0])
            ->toBe('quuxly');
    });

    test('Given every known sort key, the request is accepted', function (string $sort) {
        foreach (['asc', 'desc'] as $direction) {
            $this->get(route('words.index', ['sort' => $sort, 'direction' => $direction]))
                ->assertStatus(200);
        }
    })->with(['alphabetical', 'popularity', 'letters', 'created_at']);

    test('Given an unknown sort key, the request falls back to the default sort', function () {
        $response = $this->get(route('words.index', ['sort' => 'text; DROP TABLE words; --', 'direction' => 'asc']));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('filters.sort', 'created_at'));
    });

    test('Given a tampered sort or direction, the filters echoed back are sanitised', function () {
        $response = $this->get(route('words.index', [
            'sort' => 'letters,(SELECT 1)',
            'direction' => 'asc,(SELECT CASE WHEN 1=1 THEN 1 ELSE 0 END)',
        ]));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'created_at')
                ->where('filters.direction', 'desc')
            );
    });

    test('Given an array-valued sort or direction, the request succeeds instead of erroring', function (string $query) {
        $response = $this->get('/words?'.$query);

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'created_at')
                ->where('filters.direction', 'desc')
            );
    })->with([
        'bracketed direction' => 'direction[]=asc&direction[]=id',
        'bracketed sort' => 'sort[]=letters&sort[]=id',
        'repeated direction' => 'direction=asc&direction=id',
        'repeated sort' => 'sort=letters&sort=id',
        'both bracketed' => 'sort[]=letters&direction[]=asc',
    ]);

    test('Given an array-valued filter, the request succeeds and echoes a scalar', function (string $query, string $key, mixed $expected) {
        $response = $this->get('/words?'.$query);

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('filters.'.$key, $expected)
                ->etc()
            );
    })->with([
        'array search' => ['search[]=a&search[]=b', 'search', ''],
        'array starts_with' => ['starts_with[]=a&starts_with[]=b', 'starts_with', ''],
        'array syllables' => ['syllables[]=2&syllables[]=3', 'syllables', null],
        'array length' => ['length[]=2&length[]=3', 'length', null],
    ]);
});
