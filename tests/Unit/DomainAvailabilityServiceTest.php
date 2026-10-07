<?php

use App\Models\Word;
use App\Services\DomainAvailabilityService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Checking a word's .com against Verisign's free RDAP endpoint.
 *
 * Given any word,
 * When its .com is looked up,
 * Then only a definitive RDAP 404 may be reported as available — every
 * failure mode is check_failed, and no API key is ever sent.
 */
beforeEach(function () {
    $this->service = new DomainAvailabilityService;

    config()->set('domain.retry_sleep_ms', 0);
});

describe('classifying a domain against RDAP', function () {
    test('Given an unregistered domain, the RDAP 404 means the .com is available', function () {
        Http::fake(['*' => Http::response(null, 404)]);

        expect($this->service->checkComAvailability('blorg'))->toBe(Word::DOMAIN_AVAILABLE);
    });

    test('Given a registered domain, the RDAP 200 means the .com is taken', function () {
        Http::fake(['*' => Http::response(['objectClassName' => 'domain'], 200)]);

        expect($this->service->checkComAvailability('example'))->toBe(Word::DOMAIN_TAKEN);
    });

    test('Given an unregistered domain, the request hits the Verisign .com RDAP path without any auth header', function () {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->service->checkComAvailability('blorg');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://rdap.verisign.com/com/v1/domain/blorg.com'
            && ! $request->hasHeader('Authorization')
            && $request->method() === 'GET');
    });

    test('Given surrounding whitespace or mixed case, the label is trimmed and lowercased before the request', function () {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->service->checkComAvailability(' BLORG ');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://rdap.verisign.com/com/v1/domain/blorg.com');
    });
});

describe('failing closed when RDAP cannot be reached', function () {
    test('Given a persistent server error, the result is check_failed after every attempt', function () {
        Http::fake(['*' => Http::response(null, 500)]);

        $result = $this->service->checkComAvailability('blorg');

        expect($result)->toBe(Word::DOMAIN_CHECK_FAILED);
        expect($result)->not->toBe(Word::DOMAIN_AVAILABLE);
        Http::assertSentCount(3);
    });

    test('Given a rate limit response, the result is check_failed rather than a guess', function () {
        Http::fake(['*' => Http::response(null, 429)]);

        expect($this->service->checkComAvailability('blorg'))->toBe(Word::DOMAIN_CHECK_FAILED);
    });

    test('Given a connection error on every attempt, no exception escapes', function () {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Connection timed out')]);

        expect($this->service->checkComAvailability('blorg'))->toBe(Word::DOMAIN_CHECK_FAILED);
        Http::assertSentCount(3);
    });

    test('Given a label that is not a valid DNS label, the result is check_failed with no request sent', function (string $text) {
        Http::fake();

        expect($this->service->checkComAvailability($text))->toBe(Word::DOMAIN_CHECK_FAILED);
        Http::assertNothingSent();
    })->with([
        'empty string' => [''],
        'whitespace only' => ['   '],
        'inner space' => ['not valid'],
        'leading hyphen' => ['-leading'],
        'trailing hyphen' => ['trailing-'],
        'dot' => ['has.dot'],
        'underscore' => ['under_score'],
        'too long' => [str_repeat('a', 64)],
    ]);
});

describe('retrying a flaky RDAP lookup', function () {
    test('Given a server error that recovers on the second attempt, the real verdict wins', function () {
        Http::fakeSequence()
            ->push('', 500)
            ->push(null, 404);

        expect($this->service->checkComAvailability('blorg'))->toBe(Word::DOMAIN_AVAILABLE);
        Http::assertSentCount(2);
    });

    test('Given a 404, the lookup is never retried', function () {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->service->checkComAvailability('blorg');

        Http::assertSentCount(1);
    });
});
