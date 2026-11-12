<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScheduleRiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'occurrence_start',
        'probability',
        'model_version',
        'assessed_at',
        'schedule_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_start' => 'datetime',
            'probability' => 'float',
            'assessed_at' => 'datetime',
            'schedule_updated_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function notification(): HasOne
    {
        return $this->hasOne(Notification::class, 'risk_assessment_id');
    }
}
