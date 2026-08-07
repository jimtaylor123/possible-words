<?php

/*
|--------------------------------------------------------------------------
| Load / Stress Tests
|--------------------------------------------------------------------------
|
| These tests hit a real, running HTTP server via Pest's Stressless plugin
| (which drives k6 under the hood). They are NOT part of the regular
| Unit/Feature suites because they need a live server + seeded database.
|
| Run with: ./vendor/bin/pest tests/Load
| Requires a server to be running first: make start  (then it uses APP_URL)
| or override with: LOAD_BASE_URL=http://localhost:8002 ./vendor/bin/pest tests/Load
|
*/

use function Pest\Stressless\stress;

function loadBaseUrl(): string
{
    $override = getenv('LOAD_BASE_URL');

    if ($override !== false && $override !== '') {
        return rtrim($override, '/');
    }

    $env = file_get_contents(dirname(__DIR__, 2).'/.env') ?: '';

    if (preg_match('/^APP_URL=(.*)$/m', $env, $matches)) {
        return rtrim(trim($matches[1], '"'), '/');
    }

    return 'http://localhost:8000';
}

$baseUrl = loadBaseUrl();

it('serves the homepage under load', function () use ($baseUrl) {
    $result = stress($baseUrl.'/')
        ->concurrently(requests: 5)
        ->for(5)
        ->seconds()
        ->get();

    expect($result->requests()->failed()->count())->toBe(0);
    expect($result->requests()->duration()->p95())->toBeLessThan(500);
});

it('serves word detail pages under load', function () use ($baseUrl) {
    $result = stress($baseUrl.'/words/quohit')
        ->concurrently(requests: 5)
        ->for(5)
        ->seconds()
        ->get();

    expect($result->requests()->failed()->count())->toBe(0);
    expect($result->requests()->duration()->p95())->toBeLessThan(500);
});

it('serves the about page under load', function () use ($baseUrl) {
    $result = stress($baseUrl.'/about')
        ->concurrently(requests: 5)
        ->for(5)
        ->seconds()
        ->get();

    expect($result->requests()->failed()->count())->toBe(0);
    expect($result->requests()->duration()->p95())->toBeLessThan(500);
});
