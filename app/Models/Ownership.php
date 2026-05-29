<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ownership extends Model
{
    protected $fillable = [
        'word_id',
        'user_id',
        'start_at',
        'end_at',
        'stripe_session_id',
        'status',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' &&
               $this->start_at <= now() &&
               $this->end_at >= now();
    }
}
