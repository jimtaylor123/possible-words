<?php

/*
|--------------------------------------------------------------------------
| Production Load Tests
|--------------------------------------------------------------------------
|
| Deliberately separate from tests/Load (dev suite). These hit the REAL
| production deployment (Bref/Lambda + Turso) via Pest Stressless (k6).
|
| This makes real requests against your live site and consumes AWS usage
| (Lambda invocations, GB-seconds). Keep concurrency + duration modest.
|
| Guards:
|   - LOAD_BASE_URL must be set explicitly (composer script does this)
|   - PROD_LOAD_CONFIRM=1 must be set, otherwise tests are skipped
|
| Run: PROD_LOAD_CONFIRM=1 composer test:load:prod
|
| Overrides (all optional):
|   LOAD_CONCURRENCY   concurrent requests (default 5)
|   LOAD_DURATION      test duration seconds (default 10)
|   LOAD_P95_MAX_MS    p95 latency threshold (default 3000, cold-start aware)
|   LOAD_WORD_SLUG     word detail slug to exercise (default: none)
|
| NOTE: this AWS account has a Lambda concurrency limit of 10 (see
| `aws lambda get-account-settings`). Concurrent requests at/beyond that
| ceiling are throttled and Lambda returns 503s, so keep LOAD_CONCURRENCY
| well below 10 (default 5).
|
*/

use function Pest\Stressless\stress;

$baseUrl = getenv('LOAD_BASE_URL');
$confirmed = getenv('PROD_LOAD_CONFIRM') === '1';

if ($baseUrl === false || $baseUrl === '') {
    throw new RuntimeException('LOAD_BASE_URL is required. Run: PROD_LOAD_CONFIRM=1 composer test:load:prod');
}

$baseUrl = rtrim($baseUrl, '/');
$concurrency = (int) (getenv('LOAD_CONCURRENCY') ?: '5');
$duration = (int) (getenv('LOAD_DURATION') ?: '10');
$maxP95 = (int) (getenv('LOAD_P95_MAX_MS') ?: '3000');
$wordSlug = getenv('LOAD_WORD_SLUG') ?: null;

$confirmMessage = 'PROD_LOAD_CONFIRM=1 is required to run production load tests (real requests = real AWS usage/cost).';

it('serves the production homepage without failures', function () use ($baseUrl, $concurrency, $duration, $maxP95) {
    $result = stress($baseUrl.'/')
        ->concurrently(requests: $concurrency)
        ->for($duration)
        ->seconds()
        ->get();

    expect($result->requests()->failed()->count())->toBe(0);
    expect($result->requests()->duration()->p95())->toBeLessThan($maxP95);
})->skip(! $confirmed, $confirmMessage);

it('serves the production about page without failures', function () use ($baseUrl, $concurrency, $duration, $maxP95) {
    $result = stress($baseUrl.'/about')
        ->concurrently(requests: $concurrency)
        ->for($duration)
        ->seconds()
        ->get();

    expect($result->requests()->failed()->count())->toBe(0);
    expect($result->requests()->duration()->p95())->toBeLessThan($maxP95);
})->skip(! $confirmed, $confirmMessage);

it('serves a production word detail page without failures', function () use ($baseUrl, $concurrency, $duration, $maxP95, $wordSlug) {
    $result = stress($baseUrl.'/words/'.$wordSlug)
        ->concurrently(requests: $concurrency)
        ->for($duration)
        ->seconds()
        ->get();

    expect($result->requests()->failed()->count())->toBe(0);
    expect($result->requests()->duration()->p95())->toBeLessThan($maxP95);
})->skip(! $confirmed || $wordSlug === null, 'Set LOAD_WORD_SLUG to exercise a word detail page, plus PROD_LOAD_CONFIRM=1.');
