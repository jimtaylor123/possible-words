<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Limits Edge TTS requests to one per minute.
 *
 * Each request reserves a slot for one minute. The reserved instant is the
 * next whole second at or after the current time, and the slot deadline is
 * that reserved instant plus one minute. The deadline is calculated from a
 * copy of the reserved instant so the two values are independent and never
 * alias each other.
 */
class TtsRateLimiter
{
    private const NAME = 'edge-tts';

    public function acquire(): bool
    {
        $now = now();
        [, $nextAvailableAt] = $this->slotInstants($now);

        if (DB::table('tts_rate_limits')->insertOrIgnore([
            'name' => self::NAME,
            'next_available_at' => $nextAvailableAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1) {
            return true;
        }

        return DB::table('tts_rate_limits')
            ->where('name', self::NAME)
            ->where('next_available_at', '<=', $now)
            ->update([
                'next_available_at' => $nextAvailableAt,
                'updated_at' => $now,
            ]) === 1;
    }

    public function nextAvailableAt(): CarbonInterface
    {
        $now = now();
        $nextAvailableAt = DB::table('tts_rate_limits')
            ->where('name', self::NAME)
            ->value('next_available_at');

        return $nextAvailableAt === null ? $now->addMinute() : $now->parse($nextAvailableAt);
    }

    /**
     * Calculate the instants for a single reserved slot.
     *
     * The reserved instant is the next whole second at or after the given
     * time. The slot deadline is the reserved instant plus one minute and is
     * derived from a copy of it, so calculating the deadline never mutates
     * the reserved instant and the two values stay independent.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface} The reserved instant and the slot deadline.
     */
    protected function slotInstants(CarbonInterface $now): array
    {
        $reservedAt = $now->copy()->startOfSecond();
        if ($reservedAt->lessThan($now)) {
            $reservedAt->addSecond();
        }

        // Copy before mutating so the reserved instant is not aliased by the
        // slot deadline, which keeps the two values independent.
        $nextAvailableAt = $reservedAt->copy()->addMinute();

        return [$reservedAt, $nextAvailableAt];
    }
}
