<?php

use App\Models\Word;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The opt-in "only words with a free .com" filter.
 *
 * Given visitors toggle the filter,
 * When they browse or open the word page,
 * Then only definitively available domains are shown when it is on, and the
 * entire response stays byte-for-byte identical to the pre-feature payload
 * when it is off.
 */
function domainFilterWord(array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => 'blorg',
        'slug' => 'blorg',
        'syllables' => 2,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
        'published_at' => now(),
    ], $attrs));
}

/**
 * Create one publishable word per domain verdict and return their texts.
 *
 * @return array<int, string>
 */
function domainFilterFixture(): array
{
    domainFilterWord(['text' => 'freeword', 'slug' => 'freeword', 'domain_status' => Word::DOMAIN_AVAILABLE]);
    domainFilterWord(['text' => 'takenword', 'slug' => 'takenword', 'domain_status' => Word::DOMAIN_TAKEN]);
    domainFilterWord(['text' => 'failedword', 'slug' => 'failedword', 'domain_status' => Word::DOMAIN_CHECK_FAILED]);
    domainFilterWord(['text' => 'uncheckedword', 'slug' => 'uncheckedword']);

    return ['freeword', 'takenword', 'failedword', 'uncheckedword'];
}

/**
 * The texts of the browse list for a given query, sorted for comparison.
 *
 * @return array<int, string>
 */
function browseTexts(?array $query): array
{
    $data = test()->get(route('words.index', $query ?? []))->inertiaProps('words.data');

    return collect($data)->pluck('text')->sort()->values()->all();
}

describe('filtering the catalogue by .com availability', function () {
    test('Given mixed domain verdicts, the filter returns only the available .com words', function () {
        $all = domainFilterFixture();

        $this->get(route('words.index', ['domain_available' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->has('words.data', 1)
                ->where('words.data.0.text', 'freeword')
                ->where('filters.domain_available', 1)
            );

        expect($all)->toContain('freeword');
    });

    test('Given a word that has never been checked, it is never in the filtered set', function () {
        domainFilterWord(['text' => 'uncheckedword', 'slug' => 'uncheckedword']);

        $this->get(route('words.index', ['domain_available' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('words.data', 0));
    });

    test('Given the filter combined with a search, the two intersect', function () {
        domainFilterWord(['text' => 'blorg', 'slug' => 'blorg', 'domain_status' => Word::DOMAIN_AVAILABLE]);
        domainFilterWord(['text' => 'blorger', 'slug' => 'blorger', 'domain_status' => Word::DOMAIN_TAKEN]);
        domainFilterWord(['text' => 'zorp', 'slug' => 'zorp', 'domain_status' => Word::DOMAIN_AVAILABLE]);

        $this->get(route('words.index', ['domain_available' => 1, 'search' => 'blor']))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 1)
                ->where('words.data.0.text', 'blorg')
                ->where('filters.domain_available', 1)
            );
    });

    test('Given more available words than one page, page 2 keeps the filter', function () {
        for ($i = 0; $i < 25; $i++) {
            domainFilterWord(['text' => 'available'.$i, 'slug' => 'available'.$i, 'domain_status' => Word::DOMAIN_AVAILABLE]);
        }
        domainFilterWord(['text' => 'takenword', 'slug' => 'takenword', 'domain_status' => Word::DOMAIN_TAKEN]);

        $this->get(route('words.index', ['domain_available' => 1, 'page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->has('words.data', 5)
                ->where('filters.domain_available', 1)
                // withQueryString() keeps the filter on the paginator links.
                ->where('words.prev_page_url', fn ($url) => str_contains((string) $url, 'domain_available=1'))
            );
    });
});

describe('keeping filter-off responses byte-for-byte identical', function () {
    test('Given the param absent, no domain key leaks into the payload', function () {
        domainFilterFixture();

        $this->get(route('words.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('filters.domain_available')
                ->missing('words.data.0.domain_status')
            );
    });

    test('Given absent, zero and array variants of the param, the word set is identical', function () {
        $expected = collect(domainFilterFixture())->sort()->values()->all();

        expect(browseTexts(null))->toBe($expected)
            ->and(browseTexts(['domain_available' => 0]))->toBe($expected)
            // A bracketed param arrives as an array, is treated as absent by
            // queryString(), and must not 500.
            ->and(browseTexts(['domain_available' => [1]]))->toBe($expected);
    });

    test('Given a non-activating param value, no filter key is reflected back', function (array $query) {
        domainFilterFixture();

        $this->get(route('words.index', $query))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('filters.domain_available'))
            ->assertInertia(fn ($page) => $page->has('words.data', 4));
    })->with([
        'zero' => [['domain_available' => 0]],
        'array' => [['domain_available' => [1]]],
    ]);
});

describe('the word detail payload', function () {
    test('Given a word with a domain verdict, its show page carries domain_status', function (string $status) {
        $word = domainFilterWord([
            'text' => 'blorg',
            'slug' => 'blorg',
            'domain_status' => $status,
        ]);

        $this->get(route('words.show', $word))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Words/Show')
                ->where('word.domain_status', $status)
                // Only the status is passed to the UI; the storage detail stays hidden.
                ->missing('word.domain_checked_at')
            );
    })->with([
        'available' => [Word::DOMAIN_AVAILABLE],
        'taken' => [Word::DOMAIN_TAKEN],
        'unchecked' => [Word::DOMAIN_UNCHECKED],
        'check_failed' => [Word::DOMAIN_CHECK_FAILED],
    ]);
});
