<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class WordBatchDefinitionLock
{
    private const TTL = 10;

    public static function acquire(int $batchId): ?Lock
    {
        $lock = Cache::lock("word-batch-definitions:{$batchId}", self::TTL);

        return $lock->get() ? $lock : null;
    }
}
