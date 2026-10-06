<?php

namespace App\Services;

use App\Models\Word;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Look up whether a word's .com is registered, via Verisign's free RDAP
 * endpoint (no API key exists anywhere in this feature).
 *
 * Fails closed, exactly like DictionaryService::classify(): only a definitive
 * RDAP 404 ever produces "available" — a timeout, a 429 or any other
 * unexpected status is check_failed, because presenting an unknown domain as
 * free is the one outcome that would make the feature lie.
 */
class DomainAvailabilityService
{
    /**
     * DNS label guard: lowercase letters, digits and inner hyphens only,
     * 1-63 chars, not starting or ending with a hyphen. Production words are
     * phonotactic a-z, but a garbage label must fail locally rather than be
     * sent to the registry.
     */
    private const LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Returns one of the Word::DOMAIN_* constants. Never throws: every
     * failure path reports check_failed so the caller can always record a
     * result and a timestamp.
     */
    public function checkComAvailability(string $text): string
    {
        $label = strtolower(trim($text));

        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            return Word::DOMAIN_CHECK_FAILED;
        }

        $url = rtrim((string) config('domain.rdap_base_url'), '/').'/'.$label.'.com';

        try {
            $response = $this->getWithRetry($url);
        } catch (Throwable) {
            return Word::DOMAIN_CHECK_FAILED;
        }

        if ($response->notFound()) {
            // RDAP's definitive "object does not exist" — the domain is free.
            return Word::DOMAIN_AVAILABLE;
        }

        if ($response->successful()) {
            return Word::DOMAIN_TAKEN;
        }

        return Word::DOMAIN_CHECK_FAILED;
    }

    /**
     * GET an RDAP path, retrying transient failures.
     *
     * A 200 or a 404 is a real answer and is returned immediately. Any other
     * status (403, 429, 5xx, ...) and any transport exception is retried with
     * a linear backoff; the last failure is re-thrown so the caller records
     * check_failed rather than a guessed verdict.
     */
    private function getWithRetry(string $url): Response
    {
        $attempts = max(1, (int) config('domain.attempts', 3));
        $sleepMs = (int) config('domain.retry_sleep_ms', 400);
        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout((int) config('domain.timeout', 10))->get($url);

                if ($response->successful() || $response->notFound() || $attempt === $attempts) {
                    return $response;
                }

                $lastError = "unexpected HTTP {$response->status()}";
            } catch (Throwable $e) {
                $lastError = $e->getMessage();

                if ($attempt === $attempts) {
                    throw $e;
                }
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * $attempt * 1000);
            }
        }

        throw new RuntimeException("RDAP request failed: {$lastError}");
    }
}
