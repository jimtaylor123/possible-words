<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Word extends Model
{
    /** @use HasFactory<\Database\Factories\WordFactory> */
    use HasFactory, Searchable, SoftDeletes;

    public const DICTIONARY_UNCHECKED = 'unchecked';

    public const DICTIONARY_CHECK_FAILED = 'check_failed';

    public const DICTIONARY_NOT_FOUND = 'not_found';

    public const DICTIONARY_EXISTS_AS_NAME = 'exists_as_name';

    public const DICTIONARY_EXISTS_AS_WORD = 'exists_as_word';

    /**
     * Dictionary verdicts that make a word safe to publish as an unused word.
     *
     * 'unchecked' and 'check_failed' are deliberately absent: a word whose lookup
     * has not succeeded is not a confirmed-unused word.
     *
     * @var array<int, string>
     */
    public const PUBLISHABLE_DICTIONARY_STATUSES = [
        self::DICTIONARY_NOT_FOUND,
        self::DICTIONARY_EXISTS_AS_NAME,
    ];

    /**
     * Word URLs use the slug (see routes/web.php `{word}`).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * What Scout indexes and searches for typeahead suggestions.
     */
    public function toSearchableArray(): array
    {
        return ['text' => $this->text];
    }

    protected $fillable = [
        'text',
        'phonemes',
        'syllables',
        'status',
        'dictionary_status',
        'dictionary_checked_at',
        'dictionary_data',
        'ipa',
        'audio_url',
        'owner_user_id',
        'owned_until',
        'slug',
        'generated_at',
        'published_at',
    ];

    protected $casts = [
        'phonemes' => 'array',
        'dictionary_checked_at' => 'datetime',
        'dictionary_data' => 'array',
        'owned_until' => 'datetime',
        'generated_at' => 'datetime',
        'published_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Words safe to show on the public site: available, released, not withdrawn,
     * and carrying a dictionary verdict that says nothing was found.
     */
    public function scopePublishable(Builder $query): void
    {
        $query->where('status', 'available')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('dictionary_status', self::PUBLISHABLE_DICTIONARY_STATUSES);
    }

    public function isPublishable(): bool
    {
        return $this->status === 'available'
            && ! $this->trashed()
            && $this->published_at !== null
            && $this->published_at->lte(now())
            && in_array($this->dictionary_status, self::PUBLISHABLE_DICTIONARY_STATUSES, true);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($word) {
            if (empty($word->slug)) {
                $word->slug = Str::slug($word->text);
            }
        });
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(Definition::class);
    }

    public function ownerships(): HasMany
    {
        return $this->hasMany(Ownership::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(Favourite::class);
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function favouritedBy()
    {
        return $this->belongsToMany(User::class, 'favourites')
            ->using(Favourite::class)
            ->withTimestamps();
    }

    public function getTopDefinitionsAttribute()
    {
        return $this->definitions()->orderBy('votes_count', 'desc')->limit(5)->get();
    }
}
