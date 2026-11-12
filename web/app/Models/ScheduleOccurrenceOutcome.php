<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleOccurrenceOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'occurrence_start',
        'outcome',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_start' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
