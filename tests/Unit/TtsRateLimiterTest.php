<?php

use App\Services\TtsRateLimiter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

function ttsRateLimiterWithExposedSlotInstants(): TtsRateLimiter
{
    return new class extends TtsRateLimiter
    {
        public function slotInstantsFor(CarbonInterface $now): array
        {
            return $this->slotInstants($now);
        }
    };
}

test('it permits one Edge TTS request per minute', function () {
    Carbon::setTestNow('2026-10-22 12:00:00.500000');

    try {
        $limiter = new TtsRateLimiter;

        // The first request reserves the slot, the second one within the
        // same minute is rejected.
        expect($limiter->acquire())->toBeTrue()
            ->and($limiter->acquire())->toBeFalse();

        // The slot is still held a second before the deadline.
        Carbon::setTestNow('2026-10-22 12:01:00');

        expect($limiter->acquire())->toBeFalse();

        // Once the deadline passes the slot is released again.
        Carbon::setTestNow('2026-10-22 12:01:01');

        expect($limiter->acquire())->toBeTrue()
            ->and(DB::table('tts_rate_limits')->value('next_available_at'))->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('it calculates the slot deadline a full minute after the reserved instant', function () {
    [$reservedAt, $nextAvailableAt] = ttsRateLimiterWithExposedSlotInstants()
        ->slotInstantsFor(Carbon::parse('2026-10-22 12:00:00.500000'));

    // The reserved instant is the next whole second at or after now.
    expect($reservedAt->toDateTimeString())->toBe('2026-10-22 12:00:01')
        // The deadline is a full minute after the reserved instant, so it is
        // not derived from the unrounded time.
        ->and($nextAvailableAt->toDateTimeString())->toBe('2026-10-22 12:01:01')
        ->and($nextAvailableAt->getTimestamp() - $reservedAt->getTimestamp())->toBe(60);
});

test('it keeps the reserved instant and the slot deadline independent', function () {
    [$reservedAt, $nextAvailableAt] = ttsRateLimiterWithExposedSlotInstants()
        ->slotInstantsFor(Carbon::parse('2026-10-22 12:00:00.500000'));

    // Calculating the deadline must not mutate the reserved instant, which
    // only holds when the deadline is derived from a copy.
    expect($reservedAt->toDateTimeString())->toBe('2026-10-22 12:00:01');

    // Mutating one instant leaves the other untouched.
    $nextAvailableAt->addSecond();

    expect($reservedAt->toDateTimeString())->toBe('2026-10-22 12:00:01')
        ->and($nextAvailableAt->toDateTimeString())->toBe('2026-10-22 12:01:02');
});

test('it reserves the current instant when now falls exactly on a whole second', function () {
    [$reservedAt, $nextAvailableAt] = ttsRateLimiterWithExposedSlotInstants()
        ->slotInstantsFor(Carbon::parse('2026-10-22 12:00:00'));

    // The instant is not rounded up, because it is already a whole second.
    expect($reservedAt->toDateTimeString())->toBe('2026-10-22 12:00:00')
        ->and($nextAvailableAt->toDateTimeString())->toBe('2026-10-22 12:01:00')
        ->and($nextAvailableAt->getTimestamp() - $reservedAt->getTimestamp())->toBe(60);
});
