<?php

namespace App\Models;

use App\Enums\Category;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity_id',
        'source_schedule_id',
        'source_occurrence_start',
        'source_schedule_updated_at',
        'source_schedule_fingerprint',
        'category',
        'suggested_start',
        'suggested_end',
        'confidence_score',
        'is_suitable',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'suggested_start' => 'datetime',
            'suggested_end' => 'datetime',
            'confidence_score' => 'decimal:4',
            'is_suitable' => 'boolean',
            'generated_at' => 'datetime',
            'source_occurrence_start' => 'datetime',
            'source_schedule_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function sourceSchedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'source_schedule_id');
    }
}
