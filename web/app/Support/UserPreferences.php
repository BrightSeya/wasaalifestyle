<?php

namespace App\Support;

/**
 * Typed access to the users.preferences JSON column, so callers never touch raw array keys.
 * Also the single place that defines default preferences for a brand-new user.
 */
final readonly class UserPreferences
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const PERIODS = ['morning', 'afternoon', 'evening'];

    /**
     * @param  array<string, array<string, bool>>  $availability  day => [period => bool]
     */
    public function __construct(
        public array $availability,
        public int $peakStartHour,
        public int $peakEndHour,
        public int $preferredBreakMinutes,
        public bool $notifyConflicts,
        public bool $notifyDailySummary,
        public bool $notifyRecommendations,
    ) {}

    public static function default(): self
    {
        $weekday = ['morning' => true, 'afternoon' => true, 'evening' => false];
        $weekend = ['morning' => false, 'afternoon' => true, 'evening' => false];

        return new self(
            availability: [
                'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday, 'thu' => $weekday, 'fri' => $weekday,
                'sat' => $weekend, 'sun' => $weekend,
            ],
            peakStartHour: 9,
            peakEndHour: 17,
            preferredBreakMinutes: 15,
            notifyConflicts: true,
            notifyDailySummary: true,
            notifyRecommendations: true,
        );
    }

    /**
     * Build from the raw users.preferences array, falling back to defaults for any missing keys.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        $defaults = self::default();
        $raw ??= [];

        $availability = [];
        foreach (self::DAYS as $day) {
            foreach (self::PERIODS as $period) {
                $availability[$day][$period] = (bool) ($raw['availability'][$day][$period] ?? $defaults->availability[$day][$period]);
            }
        }

        return new self(
            availability: $availability,
            peakStartHour: (int) ($raw['peak_start_hour'] ?? $defaults->peakStartHour),
            peakEndHour: (int) ($raw['peak_end_hour'] ?? $defaults->peakEndHour),
            preferredBreakMinutes: (int) ($raw['preferred_break_minutes'] ?? $defaults->preferredBreakMinutes),
            notifyConflicts: (bool) ($raw['notify_conflicts'] ?? $defaults->notifyConflicts),
            notifyDailySummary: (bool) ($raw['notify_daily_summary'] ?? $defaults->notifyDailySummary),
            notifyRecommendations: (bool) ($raw['notify_recommendations'] ?? $defaults->notifyRecommendations),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'availability' => $this->availability,
            'peak_start_hour' => $this->peakStartHour,
            'peak_end_hour' => $this->peakEndHour,
            'preferred_break_minutes' => $this->preferredBreakMinutes,
            'notify_conflicts' => $this->notifyConflicts,
            'notify_daily_summary' => $this->notifyDailySummary,
            'notify_recommendations' => $this->notifyRecommendations,
        ];
    }

    /**
     * @return list<string>
     */
    public static function days(): array
    {
        return self::DAYS;
    }

    /**
     * @return list<string>
     */
    public static function periods(): array
    {
        return self::PERIODS;
    }
}
