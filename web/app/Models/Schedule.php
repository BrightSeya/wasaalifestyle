<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'category',
        'start_datetime',
        'end_datetime',
        'priority',
        'is_recurring',
        'recurrence_rule',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'is_recurring' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
