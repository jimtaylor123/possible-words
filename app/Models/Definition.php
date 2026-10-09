<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Definition extends Model
{
    public const ORIGIN_AI = 'ai';

    /**
     * The text shown in place of a removed definition's original text.
     */
    public const REMOVED_TEXT = '[deleted]';

    protected $fillable = [
        'word_id',
        'user_id',
        'text',
        'part_of_speech',
        'example_sentence',
        'origin',
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

    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function updateVotesCount()
    {
        $this->votes_count = $this->votes()->count();
        $this->save();
    }

    /**
     * Replace a removed definition's text with the placeholder on the way out.
     *
     * This lives in serialization rather than in the Vue template on purpose. Inertia
     * serialises models through toArray() (Response::resolveArrayableProperties ->
     * getArrayableItems), and Laravel calls toArray() on every eager-loaded relation
     * (HasAttributes::relationsToArray). So overriding it here covers every path from
     * the server to a browser in one place:
     *
     *   - WordController::show()        the word page's definitions prop
     *   - WordController::index()       the browse-card teaser
     *   - WordController::favourites()  the favourites-card teaser
     *   - App\Events\DefinitionCreated  public $definition, json-encoded as-is
     *
     * A v-if in Show.vue would not: any definition that reaches a page prop ships its
     * full text inside the data-page payload, readable in view source and devtools,
     * whatever the template chooses to paint over it.
     *
     * This is serialization only — the database row is untouched, so nothing is
     * destroyed and fresh()->text still returns the original.
     */
    public function toArray(): array
    {
        $attributes = parent::toArray();

        // array_key_exists guards the opposite case: if text were ever hidden or
        // unselected, writing the key unconditionally would add it back to the payload.
        if ($this->removed_at !== null && array_key_exists('text', $attributes)) {
            $attributes['text'] = self::REMOVED_TEXT;
        }

        return $attributes;
    }
}
