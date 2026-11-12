<?php

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'message',
        'type',
        'is_read',
        'risk_assessment_id',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(ScheduleRiskAssessment::class);
    }

    public function isCommitmentRiskAlert(): bool
    {
        return $this->risk_assessment_id !== null;
    }
}
