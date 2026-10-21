<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WordBatchRunLock
{
    private const NAME = 'words:process-batches';

    public function acquire(): ?string
    {
        $token = (string) Str::uuid();
        $now = now();
        $expiresAt = $now->copy()->addSeconds((int) config('word-batch.lease_ttl_seconds'));
        if (DB::table('word_batch_run_locks')->insertOrIgnore(['name' => self::NAME, 'token' => $token, 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now]) === 1) {
            return $token;
        }
        if (DB::table('word_batch_run_locks')->where('name', self::NAME)->where('expires_at', '<=', $now)->update(['token' => $token, 'expires_at' => $expiresAt, 'updated_at' => $now]) === 1) {
            return $token;
        }

        return null;
    }

    public function release(string $token): void
    {
        DB::table('word_batch_run_locks')->where('name', self::NAME)->where('token', $token)->delete();
    }
}
