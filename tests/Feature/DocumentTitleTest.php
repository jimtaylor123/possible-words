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
 *
 * The product name is declared per environment rather than in code, so most of
 * the drift risk lives in files this harness never loads: .env.example is only
 * read by whoever sets up a fresh clone, and serverless.yml is only read by the
 * deploy. Those are asserted as files below, because asserting only the resolved
 * config would let phpunit.xml's APP_NAME pin hide a reverted file.
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
 * Read the APP_NAME value that a committed file at the repo root declares.
 *
 * Handles both syntaxes in play — `KEY=value` in the dotenv files and
 * `KEY: value` in serverless.yml — so the assertion is about the literal
 * content of the file rather than about anything the framework resolved at
 * boot. A file that declares no APP_NAME fails rather than returning ''.
 */
function declaredAppName(string $file): string
{
    $path = dirname(__DIR__, 2).'/'.$file;

    expect(file_exists($path), "{$file} must exist at the repo root")->toBeTrue();

    preg_match('/^[ \t]*APP_NAME[ \t]*[=:][ \t]*(.+?)[ \t]*$/m', (string) file_get_contents($path), $matches);

    expect($matches, "{$file} must declare APP_NAME")->toHaveKey(1);

    return trim($matches[1], "\"'");
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

describe('the product name declared by each environment file', function () {
    test('Given the file a clone or a deploy reads, it declares the product name', function (string $file) {
        expect(declaredAppName($file))
            ->toBe('Possible Words')
            ->toBe(declaredAppName('.env.example'));
    })->with([
        'fresh clone' => '.env.example',
        'Playwright server' => '.env.testing',
        'production' => 'serverless.yml',
    ]);

    test('Given the harness pins APP_NAME, the resolved value cannot hide file drift', function () {
        // phpunit.xml pins APP_NAME so the suite is deterministic, and Dotenv is
        // immutable, so the pin wins over every env file at boot. Tying the
        // resolved config back to .env.example is what stops that pin from
        // masking a reverted .env.example or serverless.yml.
        expect(config('app.name'))->toBe(declaredAppName('.env.example'));
    });
});

describe('the document title', function () {
    test('Given a page, the document title is the product name and the server owns it', function (string $routeName) {
        $html = renderTitlePage($routeName);

        expect(titleOf($html))->toBe('Possible Words')
            // Inertia deletes any <title> carrying its `inertia` attribute when no
            // page supplies a <Head> title, which blanks the tab (#71). The
            // element must therefore be plain and server-rendered.
            ->and($html)->not->toContain('<title inertia');
    })->with(TITLE_PAGES);
});

describe('the mail From name', function () {
    test('Given no explicit MAIL_FROM_NAME, mail falls back to the declared product name', function () {
        // Guard that this test keeps exercising the config/mail.php fallback rather
        // than an interpolated MAIL_FROM_NAME, which would make it tautological.
        expect(env('MAIL_FROM_NAME'))->toBeNull();

        expect(config('mail.from.name'))->toBe(declaredAppName('.env.example'));
    });
});
