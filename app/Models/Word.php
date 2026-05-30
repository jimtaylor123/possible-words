<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Word extends Model
{
    /**
     * Word URLs use the slug (see routes/web.php `{word}`).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected $fillable = [
        'text',
        'phonemes',
        'syllables',
        'status',
        'ipa',
        'audio_url',
        'owner_user_id',
        'owned_until',
        'slug',
    ];

    protected $casts = [
        'phonemes' => 'array',
        'owned_until' => 'datetime',
    ];

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

    public function getTopDefinitionsAttribute()
    {
        return $this->definitions()->orderBy('votes_count', 'desc')->limit(5)->get();
    }
}
