<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Definition extends Model
{
    /**
     * The text shown in place of a removed definition's original text.
     */
    public const REMOVED_TEXT = '[deleted]';

    protected $fillable = [
        'word_id',
        'user_id',
        'text',
        'votes_count',
    ];

    /**
     * `removed_at` is server-controlled state and is deliberately NOT fillable — see
     * removeDefinition() in WordController, which assigns the attribute directly rather
     * than mass-assigning it. Mass assignment silently drops keys that are not fillable,
     * so an `update(['removed_at' => ...])` here would report success and write nothing.
     *
     * Laravel 12 has no `$dates` property (a repo-wide read of HasAttributes.php finds no
     * reference to it), so a `$dates` declaration would be an ordinary ignored property.
     * AGENTS.md requires explicit `$casts`, and Word.php / Ownership.php both declare
     * `protected $casts` with `'…_at' => 'datetime'` entries — removed_at is the same
     * shape, so it joins them there.
     */
    protected $casts = [
        'removed_at' => 'datetime',
    ];

    /**
     * Not removed — the only default. Callers opt out with `withoutRemoved()`.
     */
    public function scopeWithoutRemoved($query)
    {
        return $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function updateVotesCount()
    {
        $this->votes_count = $this->votes()->count();
        $this->save();
    }
}
