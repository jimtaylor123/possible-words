<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordBatchSlot extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const REJECTED = 'rejected';
    public const READY = 'ready';

    protected $fillable = ['word_batch_id', 'slot_number', 'word_id', 'status', 'attempts', 'retry_at', 'last_error'];

    protected function casts(): array { return ['retry_at' => 'datetime', 'last_error' => 'array']; }

    public function batch(): BelongsTo { return $this->belongsTo(WordBatch::class, 'word_batch_id'); }
    public function word(): BelongsTo { return $this->belongsTo(Word::class); }
}
