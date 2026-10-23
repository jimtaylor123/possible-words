<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSuspensionAction extends Model
{
    use HasFactory;

    public const ACTION_SUSPENDED = 'suspended';

    public const ACTION_UNSUSPENDED = 'unsuspended';

    protected $fillable = [
        'user_id',
        'admin_user_id',
        'action',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
