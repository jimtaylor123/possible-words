<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A database lease shared by every words:check-domains invocation.
 *
 * The production Lambda cache is process-local, so scheduler cache locks cannot
 * protect concurrent EventBridge deliveries. This lease lives in Turso instead.
 */
class DomainCheckRunLock
{
    private const NAME = 'words:check-domains';

    /**
     * Acquire the lease, returning its ownership token, or null when another
     * invocation owns an unexpired lease.
     */
    public function acquire(): ?string
    {
        $token = (string) Str::uuid();
        $now = now();
        $expiresAt = $now->copy()->addSeconds((int) config('domain.run_lock_ttl_seconds', 720));

        if (DB::table('domain_check_run_locks')->insertOrIgnore([
            'name' => self::NAME,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1) {
            return $token;
        }

        // This conditional update is atomic: only one concurrent invocation
        // can replace an expired lease.
        if (DB::table('domain_check_run_locks')
            ->where('name', self::NAME)
            ->where('expires_at', '<=', $now)
            ->update([
                'token' => $token,
                'expires_at' => $expiresAt,
                'updated_at' => $now,
            ]) === 1) {
            return $token;
        }

        return null;
    }

    /** Release only a lease still owned by this invocation. */
    public function release(string $token): void
    {
        DB::table('domain_check_run_locks')
            ->where('name', self::NAME)
            ->where('token', $token)
            ->delete();
    }
}
