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

    public const WHOIS_PROVIDER = 'verisign-com-whois';

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
            return $this->checkWhoisAvailability($label);
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

    /**
     * WHOIS is a registry-operated fallback for environments that cannot reach
     * the RDAP HTTPS endpoint. Only explicit registry verdicts are accepted.
     */
    protected function checkWhoisAvailability(string $label): DomainAvailabilityOutcome
    {
        $socket = null;

        try {
            $errorNumber = 0;
            $errorMessage = '';
            $socket = fsockopen(
                (string) config('domain.whois_host'),
                (int) config('domain.whois_port'),
                $errorNumber,
                $errorMessage,
                (int) config('domain.timeout', 10),
            );

            if ($socket === false) {
                throw new \RuntimeException("WHOIS connection failed: {$errorNumber} {$errorMessage}");
            }

            if (fwrite($socket, "{$label}.com\r\n") === false) {
                throw new \RuntimeException('WHOIS request write failed.');
            }

            stream_set_timeout($socket, (int) config('domain.timeout', 10));
            $response = stream_get_contents($socket, 65536);
            $metadata = stream_get_meta_data($socket);
            fclose($socket);

            if ($response === false || $metadata['timed_out']) {
                throw new \RuntimeException('WHOIS response timed out.');
            }
        } catch (Throwable) {
            if (is_resource($socket)) {
                fclose($socket);
            }

            return new DomainAvailabilityOutcome(
                Word::DOMAIN_CHECK_FAILED,
                self::WHOIS_PROVIDER,
                'transport',
                'Neither RDAP nor the WHOIS fallback could be reached.',
            );
        }

        return $this->classifyWhoisResponse($label, $response);
    }

    protected function classifyWhoisResponse(string $label, string $response): DomainAvailabilityOutcome
    {
        $domain = preg_quote("{$label}.com", '/');

        if (preg_match('/^\s*No match for\s+"?'.$domain.'"?\.?\s*$/mi', $response) === 1) {
            return new DomainAvailabilityOutcome(Word::DOMAIN_AVAILABLE, self::WHOIS_PROVIDER);
        }

        if (preg_match('/^\s*Domain Name:\s*'.$domain.'\s*$/mi', $response) === 1) {
            return new DomainAvailabilityOutcome(Word::DOMAIN_TAKEN, self::WHOIS_PROVIDER);
        }

        return new DomainAvailabilityOutcome(
            Word::DOMAIN_CHECK_FAILED,
            self::WHOIS_PROVIDER,
            'invalid_response',
            'The WHOIS fallback did not return a definitive domain verdict.',
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
