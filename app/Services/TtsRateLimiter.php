<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TtsRateLimiter
{
    private const NAME = 'edge-tts';

    public function acquire(): bool
    {
        $now = now();
        $reservedAt = $now->copy()->startOfSecond();
        if ($reservedAt->lessThan($now)) {
            $reservedAt->addSecond();
        }
        $nextAvailableAt = $reservedAt->addMinute();

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
        $nextAvailableAt = DB::table('tts_rate_limits')
            ->where('name', self::NAME)
            ->value('next_available_at');

        return $nextAvailableAt === null ? now()->addMinute() : now()->parse($nextAvailableAt);
    }
}
