<?php

use App\Services\TtsRateLimiter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

test('it permits one Edge TTS request per minute', function () {
    Carbon::setTestNow('2026-10-22 12:00:00.500000');

    try {
        $limiter = new TtsRateLimiter;

        expect($limiter->acquire())->toBeTrue()
            ->and($limiter->acquire())->toBeFalse();

        Carbon::setTestNow('2026-10-22 12:01:00');

        expect($limiter->acquire())->toBeFalse();

        Carbon::setTestNow('2026-10-22 12:01:01');

        expect($limiter->acquire())->toBeTrue()
            ->and(DB::table('tts_rate_limits')->value('next_available_at'))->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});
