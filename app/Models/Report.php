<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const REASON_WORD_UNFRESH = 'word_unfresh';

    public const REASON_WORD_OFFENSIVE = 'word_offensive';

    public const REASON_DEFINITION_OFFENSIVE = 'definition_offensive';

    /** @var array<class-string<Model>, list<string>> */
    private const REASONS_BY_REPORTABLE = [
        Word::class => [
            self::REASON_WORD_UNFRESH,
            self::REASON_WORD_OFFENSIVE,
        ],
        Definition::class => [
            self::REASON_DEFINITION_OFFENSIVE,
        ],
    ];

    protected $fillable = [
        'reporter_id',
        'reason',
        'explanation',
        'status',
        'processed_by_user_id',
        'processed_at',
        'processing_action',
        'processing_note',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function reasonsFor(Model $reportable): array
    {
        return self::REASONS_BY_REPORTABLE[$reportable::class] ?? [];
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
