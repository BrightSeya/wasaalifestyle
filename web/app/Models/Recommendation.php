<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    /** @use HasFactory<\Database\Factories\RecommendationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity_id',
        'category',
        'suggested_start',
        'suggested_end',
        'confidence_score',
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
            'generated_at' => 'datetime',
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
}
