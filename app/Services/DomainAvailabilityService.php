<?php

namespace App\Services;

use App\Models\Word;
use Illuminate\Support\Facades\Http;
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
    public const PROVIDER = 'verisign-com';

    /**
     * DNS label guard: lowercase letters, digits and inner hyphens only,
     * 1-63 chars, not starting or ending with a hyphen. Production words are
     * phonotactic a-z, but a garbage label must fail locally rather than be
     * sent to the registry.
     */
    private const LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Make exactly one request. Retrying is deliberately deferred to the
     * command so failures survive process and Lambda restarts.
     */
    public function checkComAvailability(string $text): DomainAvailabilityOutcome
    {
        $label = strtolower(trim($text));

        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            return new DomainAvailabilityOutcome(
                Word::DOMAIN_CHECK_FAILED,
                self::PROVIDER,
                'invalid_label',
                'The word is not a valid domain label.',
            );
        }

        $url = rtrim((string) config('domain.rdap_base_url'), '/').'/'.$label.'.com';

        try {
            $response = Http::timeout((int) config('domain.timeout', 10))->get($url);
        } catch (Throwable) {
            return new DomainAvailabilityOutcome(
                Word::DOMAIN_CHECK_FAILED,
                self::PROVIDER,
                'transport',
                'The RDAP provider could not be reached.',
            );
        }

        if ($response->notFound()) {
            // RDAP's definitive "object does not exist" — the domain is free.
            return new DomainAvailabilityOutcome(Word::DOMAIN_AVAILABLE, self::PROVIDER, httpStatus: 404);
        }

        if ($response->status() === 200) {
            return new DomainAvailabilityOutcome(Word::DOMAIN_TAKEN, self::PROVIDER, httpStatus: 200);
        }

        return new DomainAvailabilityOutcome(
            Word::DOMAIN_CHECK_FAILED,
            self::PROVIDER,
            'http',
            "The RDAP provider returned HTTP {$response->status()}.",
            $response->status(),
            $this->retryAfterSeconds($response->header('Retry-After')),
        );
    }

    public function retryAfterSeconds(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            $seconds = now()->diffInSeconds(\Carbon\CarbonImmutable::parse($value), false);
        } catch (Throwable) {
            return null;
        }

        return $seconds > 0 ? $seconds : null;
    }
}
