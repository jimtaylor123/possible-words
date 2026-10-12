<?php

use App\Models\Word;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The daily .com availability batch.
 *
 * Given words with no fresh verdict,
 * When words:check-domains runs,
 * Then it records a result for each due word only, throttles its RDAP
 * requests, and never lets a third-party outage fail the scheduled event.
 */
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function domainCheckableWord(string $text, array $attrs = []): Word
{
    return Word::create(array_merge([
        'text' => $text,
        'slug' => $text,
        'syllables' => 1,
        'status' => 'available',
        'dictionary_status' => Word::DICTIONARY_NOT_FOUND,
        'published_at' => now(),
    ], $attrs));
}

beforeEach(function () {
    config()->set('domain.request_interval_ms', 0);
    config()->set('domain.request_jitter_ms', 0);
    config()->set('domain.retry_base_seconds', 60);
    config()->set('domain.retry_cap_seconds', 3600);
    config()->set('domain.retry_jitter_seconds', 0);
    config()->set('domain.max_retry_after_seconds', 3600);
});

describe('recording a verdict for each due word', function () {
    test('Given an unchecked word, it becomes available and is timestamped', function () {
        $word = domainCheckableWord('freebie');

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        $word->refresh();

        expect($word->domain_status)->toBe(Word::DOMAIN_AVAILABLE)
            ->and($word->domain_checked_at)->not->toBeNull();
    });

    test('Given a registered domain, the word is recorded as taken', function () {
        $word = domainCheckableWord('takendot');

        Http::fake(['*' => Http::response(['objectClassName' => 'domain'], 200)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_TAKEN);
    });

    test('Given the API is down, the word becomes check_failed with a timestamp and never available', function () {
        $word = domainCheckableWord('outagedot');

        Http::fake(['*' => Http::response(null, 500)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        $word->refresh();

        expect($word->domain_status)->toBe(Word::DOMAIN_CHECK_FAILED)
            ->and($word->domain_status)->not->toBe(Word::DOMAIN_AVAILABLE)
            ->and($word->domain_checked_at)->not->toBeNull()
            ->and($word->domain_attempts)->toBe(1)
            ->and($word->domain_retry_at)->not->toBeNull()
            ->and($word->domain_failure)->toMatchArray(['kind' => 'http', 'http_status' => 500]);
    });

    test('Given mixed verdicts, the summary counts them', function () {
        domainCheckableWord('freebie');
        domainCheckableWord('takendot');
        domainCheckableWord('faildot');

        Http::fake([
            '*freebie.com' => Http::response(null, 404),
            '*takendot.com' => Http::response([], 200),
            '*faildot.com' => Http::response(null, 500),
        ]);

        $this->artisan('words:check-domains')
            ->expectsOutputToContain('1 available, 1 taken, 1 check_failed')
            ->assertExitCode(0);
    });
});

describe('choosing which words to check', function () {
    test('Given unchecked, due failed, and stale verdicts, unchecked words are selected first', function () {
        domainCheckableWord('stale', ['domain_status' => Word::DOMAIN_TAKEN, 'domain_checked_at' => now()->subDay()]);
        domainCheckableWord('duefailed', ['domain_status' => Word::DOMAIN_CHECK_FAILED, 'domain_retry_at' => now()->subSecond()]);
        domainCheckableWord('unchecked');
        $requested = [];

        Http::fake(function ($request) use (&$requested) {
            $requested[] = $request->url();

            return Http::response(null, 404);
        });

        $this->artisan('words:check-domains --limit=1')->assertExitCode(0);

        expect($requested[0])->toContain('/unchecked.com');
    });

    test('Given a failed word whose retry is not due, it is excluded', function () {
        $word = domainCheckableWord('deferred', [
            'domain_status' => Word::DOMAIN_CHECK_FAILED,
            'domain_retry_at' => now()->addHour(),
            'domain_attempts' => 2,
        ]);

        Http::fake();

        $this->artisan('words:check-domains')->expectsOutputToContain('No words to check')->assertExitCode(0);

        expect($word->fresh()->domain_attempts)->toBe(2);
        Http::assertNothingSent();
    });

    test('Given a conclusive retry, it clears deferred state and resets attempts', function () {
        $word = domainCheckableWord('recovered', [
            'domain_status' => Word::DOMAIN_CHECK_FAILED,
            'domain_retry_at' => now()->subSecond(),
            'domain_attempts' => 3,
            'domain_failure' => ['kind' => 'http'],
        ]);

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        $word->refresh();

        expect($word->domain_status)->toBe(Word::DOMAIN_AVAILABLE)
            ->and($word->domain_attempts)->toBe(0)
            ->and($word->domain_retry_at)->toBeNull()
            ->and($word->domain_failure)->toBeNull();
    });

    test('Given a Retry-After response, it cools down the provider before the next request', function () {
        domainCheckableWord('cooldownfirst');
        domainCheckableWord('cooldownsecond');
        config()->set('domain.max_retry_after_seconds', 1);

        Http::fake([
            '*cooldownfirst.com' => Http::response(null, 429, ['Retry-After' => '1']),
            '*cooldownsecond.com' => Http::response(null, 404),
        ]);

        $startedAt = microtime(true);
        $this->artisan('words:check-domains')->assertExitCode(0);

        expect(microtime(true) - $startedAt)->toBeGreaterThan(0.8);
        Http::assertSentCount(2);
    });

    test('Given a word checked 12 hours ago, it is left alone', function () {
        $word = domainCheckableWord('recent', [
            'domain_status' => Word::DOMAIN_TAKEN,
            'domain_checked_at' => now()->subHours(12),
        ]);

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')
            ->expectsOutputToContain('No words to check')
            ->assertExitCode(0);

        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_TAKEN);
    });

    test('Given a word checked 25 hours ago, it is re-checked — the daily refresh', function () {
        $word = domainCheckableWord('stale', [
            'domain_status' => Word::DOMAIN_TAKEN,
            'domain_checked_at' => now()->subHours(25),
        ]);

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE);
    });

    test('Given --force, a recently checked word is re-checked now', function () {
        $word = domainCheckableWord('recent', [
            'domain_status' => Word::DOMAIN_TAKEN,
            'domain_checked_at' => now()->subHours(12),
        ]);

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains --force')->assertExitCode(0);

        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE);
    });

    test('Given a withdrawn word, it is skipped', function () {
        $word = domainCheckableWord('gonedot');
        $word->delete();

        Http::fake();

        $this->artisan('words:check-domains')
            ->expectsOutputToContain('No words to check')
            ->assertExitCode(0);

        expect($word->fresh()->domain_checked_at)->toBeNull();
        Http::assertNothingSent();
    });
});

describe('chunking and time-boxing the run', function () {
    test('Given more words than a chunk, every due word is checked', function () {
        $first = domainCheckableWord('firstdot');
        $second = domainCheckableWord('seconddot');
        $third = domainCheckableWord('thirddot');

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains --chunk=1')->assertExitCode(0);

        expect($first->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE)
            ->and($second->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE)
            ->and($third->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE);
    });

    test('Given --limit, only that many words are processed and the rest stay untouched', function () {
        $first = domainCheckableWord('firstdot');
        $second = domainCheckableWord('seconddot');

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains --limit=1')->assertExitCode(0);

        expect($first->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE)
            ->and($second->fresh()->domain_status)->toBe(Word::DOMAIN_UNCHECKED)
            ->and($second->fresh()->domain_checked_at)->toBeNull();
    });

    test('Given --throttle, the option paces the run even when config disables throttling', function () {
        domainCheckableWord('pacedfirst');
        domainCheckableWord('pacedsecond');

        Http::fake(['*' => Http::response(null, 404)]);

        // beforeEach disables configured pacing, so any pacing here comes from
        // the option: two words at one request per second wait before each request.
        $startedAt = microtime(true);
        $this->artisan('words:check-domains --throttle=1')->assertExitCode(0);
        $elapsedSeconds = microtime(true) - $startedAt;

        expect($elapsedSeconds)->toBeGreaterThan(1.5);
        Http::assertSentCount(2);
    });

    test('Given persistence writes fail, --limit still caps RDAP attempts', function () {
        foreach (range(1, 10) as $number) {
            domainCheckableWord("failedwrite{$number}");
        }

        Http::fake(['*' => Http::response(null, 404)]);
        Word::updating(function (Word $word) {
            if (str_starts_with($word->text, 'failedwrite')) {
                throw new RuntimeException('Simulated per-word persistence failure');
            }
        });

        $this->artisan('words:check-domains --limit=8')
            ->expectsOutputToContain('Checked 0 of 8 attempted words')
            ->assertExitCode(0);

        Http::assertSentCount(8);
        expect(Word::whereNull('domain_checked_at')->count())->toBe(10);
    });
});

describe('the durable production run lease', function () {
    test('Given a concurrent invocation holds the database lease, it makes no RDAP request', function () {
        $word = domainCheckableWord('leasedword');

        DB::table('domain_check_run_locks')->insert([
            'name' => 'words:check-domains',
            'token' => 'concurrent-invocation',
            'expires_at' => now()->addSeconds(720),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')
            ->expectsOutputToContain('database lease')
            ->assertExitCode(0);

        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_UNCHECKED);
        Http::assertNothingSent();
    });

    test('Given a timed-out invocation left an expired lease, the next invocation resumes the due word', function () {
        $word = domainCheckableWord('resumeword');

        DB::table('domain_check_run_locks')->insert([
            'name' => 'words:check-domains',
            'token' => 'timed-out-invocation',
            'expires_at' => now()->subSecond(),
            'created_at' => now()->subMinutes(13),
            'updated_at' => now()->subMinutes(13),
        ]);
        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE)
            ->and($word->fresh()->domain_checked_at)->not->toBeNull();
        Http::assertSentCount(1);
    });

    test('Given a completed run, it releases the lease so the next invocation can acquire it', function () {
        domainCheckableWord('firstleasedword');

        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('words:check-domains')->assertExitCode(0);

        expect(DB::table('domain_check_run_locks')->count())->toBe(0);

        $second = domainCheckableWord('secondleasedword');

        // Without a released lease this run would skip with "database lease"
        // and never reach the second word.
        $this->artisan('words:check-domains')
            ->expectsOutputToContain('Checked 1 of 1 attempted words')
            ->assertExitCode(0);

        expect($second->fresh()->domain_status)->toBe(Word::DOMAIN_AVAILABLE);
    });
});

describe('the kill switch', function () {
    test('Given domain checks are disabled, no request is made and the command still succeeds', function () {
        $word = domainCheckableWord('freebie');
        config()->set('domain.enabled', false);

        Http::fake();

        $this->artisan('words:check-domains')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        Http::assertNothingSent();
        expect($word->fresh()->domain_status)->toBe(Word::DOMAIN_UNCHECKED)
            ->and($word->fresh()->domain_checked_at)->toBeNull();
    });
});
