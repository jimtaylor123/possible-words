<?php

use App\Models\User;
use App\Models\Word;

use function Pest\Laravel\get;

/**
 * The browser tab title.
 *
 * Given a visitor loads any page,
 * When the document title is read from the served markup,
 * Then it should be the product name on every page, never the framework default.
 */

/**
 * Routes that render an Inertia document and therefore own a <title>.
 *
 * /suggestions is deliberately absent: it is a JSON typeahead endpoint, not a
 * page. It returns response()->json([]) and never renders the root view, so it
 * has no document title to assert on.
 */
const TITLE_PAGES = ['home', 'about', 'words.index', 'words.favourites', 'words.show'];

const AUTHENTICATED_TITLE_PAGES = ['words.favourites'];

/**
 * Read the <title> element out of a rendered page.
 */
function titleOf(string $html): string
{
    preg_match('/<title[^>]*>(.*?)<\/title>/s', $html, $matches);

    return trim($matches[1] ?? '');
}

/**
 * Create a word to hang the word detail route off.
 *
 * The slug is suffixed so several pages can be rendered inside one test without
 * tripping the unique index on words.slug.
 */
function titleTestWord(): Word
{
    static $sequence = 0;
    $sequence++;

    return Word::create([
        'text' => 'blorg'.$sequence,
        'slug' => 'blorg'.$sequence,
        'syllables' => 2,
        'status' => 'available',
    ]);
}

/**
 * Render one page and return its markup.
 */
function renderTitlePage(string $routeName): string
{
    $word = titleTestWord();

    if (in_array($routeName, AUTHENTICATED_TITLE_PAGES, true)) {
        test()->actingAs(User::factory()->create());
    }

    $url = $routeName === 'words.show'
        ? route($routeName, $word)
        : route($routeName);

    return get($url)->assertOk()->getContent();
}

/**
 * Render every page, keyed by a label so failures name the offending route.
 */
function publicPages(): array
{
    $pages = [];
    foreach (TITLE_PAGES as $routeName) {
        $pages[$routeName] = renderTitlePage($routeName);
    }

    return $pages;
}

describe('the resolved app name', function () {
    test('Given the app boots, the name is the product name and not the framework default', function () {
        expect(config('app.name'))->toBe('Possible Words');
    });
});

describe('the document title', function () {
    test('Given a page, the document title is exactly the product name', function (string $routeName) {
        expect(titleOf(renderTitlePage($routeName)))->toBe('Possible Words');
    })->with([
        'home' => 'home',
        'about' => 'about',
        'words index' => 'words.index',
        'favourites' => 'words.favourites',
        'word detail' => 'words.show',
    ]);
});

describe('the framework default leaking into the markup', function () {
    test('Given any page, neither the title nor the markup presents Laravel', function () {
        foreach (publicPages() as $label => $html) {
            expect(titleOf($html), "title of the {$label} page")
                ->not->toBe('Laravel')
                ->not->toContain('Laravel');

            // Nothing in the served document may present the framework name.
            expect($html, "markup of the {$label} page")
                ->not->toContain('Laravel');
        }
    });
});

describe('the title falling back to the address bar', function () {
    test('Given any page, the title is never a hostname or a URL', function () {
        foreach (publicPages() as $label => $html) {
            expect(titleOf($html), "title of the {$label} page")
                ->not->toContain('jimtaylor.space')
                ->not->toContain('://')
                ->not->toContain('localhost');
        }
    });
});

describe('the mail From name', function () {
    test('Given no explicit MAIL_FROM_NAME, mail falls back to the product name', function () {
        // Guard that this test keeps exercising the config/mail.php fallback rather
        // than an interpolated MAIL_FROM_NAME, which would make it tautological.
        expect(env('MAIL_FROM_NAME'))->toBeNull();

        expect(config('mail.from.name'))->toBe('Possible Words');
    });
});
