<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReportCase extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    // Retained only for immutable cases migrated from the legacy report lifecycle.
    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_MUTED = 'muted';

    public const ACTION_DISMISSED = 'dismissed';

    public const ACTION_MUTED = 'muted';

    public const ACTION_UNMUTED = 'unmuted';

    public const ACTION_REMOVED = 'removed';

    protected $fillable = ['reason', 'status', 'active_key', 'processed_by_user_id', 'processed_at', 'processing_action', 'processing_note'];

    protected $casts = ['processed_at' => 'datetime'];

    public static function activeKey(Model $reportable, string $reason): string
    {
        return implode('|', [$reportable::class, $reportable->getKey(), $reason]);
    }

    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_DISMISSED, self::STATUS_REVIEWED, self::STATUS_MUTED];
    }

    public static function actionsFor(string $status): array
    {
        return match ($status) {
            self::STATUS_OPEN => [self::ACTION_DISMISSED, self::ACTION_REMOVED, self::ACTION_MUTED],
            self::STATUS_MUTED => [self::ACTION_UNMUTED],
            default => [],
        };
    }

    public static function statusForAction(string $action): string
    {
        return match ($action) {
            self::ACTION_DISMISSED => self::STATUS_DISMISSED,
            self::ACTION_REMOVED => self::STATUS_DISMISSED,
            self::ACTION_MUTED => self::STATUS_MUTED,
            self::ACTION_UNMUTED => self::STATUS_OPEN,
        };
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class)->orderBy('created_at')->orderBy('id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function reviewActions(): HasMany
    {
        return $this->hasMany(ReportReviewAction::class)->orderBy('created_at')->orderBy('id');
    }
}
