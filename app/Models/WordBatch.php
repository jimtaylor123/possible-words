<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WordBatch extends Model
{
    use HasFactory;

    public const SIZE = 5;

    public const GENERATING = 'generating';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const STALLED = 'stalled';

    protected $fillable = ['scheduled_for', 'target_count', 'status', 'generated_at', 'ready_at', 'published_at', 'notification_dispatched_at', 'last_progressed_at', 'attempts', 'retry_at', 'last_error', 'stalled_at'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'generated_at' => 'datetime', 'ready_at' => 'datetime', 'published_at' => 'datetime', 'notification_dispatched_at' => 'datetime', 'last_progressed_at' => 'datetime', 'retry_at' => 'datetime', 'stalled_at' => 'datetime', 'last_error' => 'array'];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(WordBatchSlot::class)->orderBy('slot_number');
    }

    protected static function booted(): void
    {
        static::saving(function (self $batch): void {
            if ((int) $batch->target_count !== self::SIZE) {
                throw new \InvalidArgumentException('Word batches must contain exactly five slots.');
            }
        });
    }

    public function scopeDue(Builder $query): void
    {
        $query->whereNotIn('status', [self::READY, self::PUBLISHED, self::FAILED])->where(fn (Builder $query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()));
    }
}
