<?php

namespace App\Support;

/**
 * Typed access to the users.preferences JSON column, including migration of legacy
 * morning/afternoon/evening checkboxes to exact weekly time ranges.
 */
final readonly class UserPreferences
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<string, list<array{start: string, end: string}>>  $availability
     */
    public function __construct(
        public array $availability,
        public int $peakStartHour,
        public int $peakEndHour,
        public int $preferredBreakMinutes,
        public bool $notifyConflicts,
        public bool $notifyRecommendations,
        public bool $personalizeRecommendations,
    ) {}

    public static function default(): self
    {
        $weekday = [['start' => '06:00', 'end' => '11:00'], ['start' => '12:00', 'end' => '17:00']];
        $weekend = [['start' => '12:00', 'end' => '17:00']];

        return new self(
            availability: [
                'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday, 'thu' => $weekday, 'fri' => $weekday,
                'sat' => $weekend, 'sun' => $weekend,
            ],
            peakStartHour: 9,
            peakEndHour: 17,
            preferredBreakMinutes: 15,
            notifyConflicts: true,
            notifyRecommendations: true,
            personalizeRecommendations: false,
        );
    }

    /**
     * Build from raw preferences. Old boolean period settings are converted in memory,
     * so users keep their current availability when they first open the updated app.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        $defaults = self::default();
        $raw ??= [];
        $availability = [];
        $periods = ['morning' => ['06:00', '11:00'], 'afternoon' => ['12:00', '17:00'], 'evening' => ['18:00', '23:00']];

        foreach (self::DAYS as $day) {
            $dayAvailability = $raw['availability'][$day] ?? null;
            if (is_array($dayAvailability) && array_is_list($dayAvailability)) {
                $availability[$day] = array_values(array_filter(array_map(
                    static fn ($range) => is_array($range) && isset($range['start'], $range['end'])
                        ? ['start' => (string) $range['start'], 'end' => (string) $range['end']]
                        : null,
                    $dayAvailability,
                )));

                continue;
            }

            // Older accounts store period flags instead of a list of custom ranges.
            $availability[$day] = [];
            foreach ($periods as $period => [$start, $end]) {
                $enabled = is_array($dayAvailability) && array_key_exists($period, $dayAvailability)
                    ? (bool) $dayAvailability[$period]
                    : in_array(['start' => $start, 'end' => $end], $defaults->availability[$day], true);
                if ((bool) $enabled) {
                    $availability[$day][] = ['start' => $start, 'end' => $end];
                }
            }
        }

        return new self(
            availability: $availability,
            peakStartHour: (int) ($raw['peak_start_hour'] ?? $defaults->peakStartHour),
            peakEndHour: (int) ($raw['peak_end_hour'] ?? $defaults->peakEndHour),
            preferredBreakMinutes: (int) ($raw['preferred_break_minutes'] ?? $defaults->preferredBreakMinutes),
            notifyConflicts: (bool) ($raw['notify_conflicts'] ?? $defaults->notifyConflicts),
            notifyRecommendations: (bool) ($raw['notify_recommendations'] ?? $defaults->notifyRecommendations),
            personalizeRecommendations: (bool) ($raw['personalize_recommendations'] ?? $defaults->personalizeRecommendations),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'availability' => $this->availability,
            'peak_start_hour' => $this->peakStartHour,
            'peak_end_hour' => $this->peakEndHour,
            'preferred_break_minutes' => $this->preferredBreakMinutes,
            'notify_conflicts' => $this->notifyConflicts,
            'notify_recommendations' => $this->notifyRecommendations,
            'personalize_recommendations' => $this->personalizeRecommendations,
        ];
    }

    /** @return list<string> */
    public static function days(): array
    {
        return self::DAYS;
    }
}
