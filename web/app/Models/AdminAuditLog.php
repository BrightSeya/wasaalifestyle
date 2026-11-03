<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    public const ACTIONS = [
        'user.created',
        'user.suspended',
        'user.reactivated',
        'user.password_reset_requested',
    ];

    protected $fillable = [
        'administrator_id',
        'affected_user_id',
        'action',
    ];

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrator_id');
    }

    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affected_user_id');
    }

    public function scopeForAction(Builder $query, ?string $action): Builder
    {
        return $query->when($action, fn (Builder $query) => $query->where('action', $action));
    }
}
