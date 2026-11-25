<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class RecurrenceLimits
{
    public const ONE_YEAR_ERROR = 'Keep the repeating task within one year of its first occurrence.';

    public static function firstOccurrence(Carbon $date, string $recurrence, ?int $weekday = null): Carbon
    {
        $first = $date->copy()->startOfDay();
        if ($recurrence !== 'weekly') {
            return $first;
        }

        $weekday ??= $first->dayOfWeekIso;

        return $first->addDays(($weekday - $first->dayOfWeekIso + 7) % 7);
    }

    public static function lastOccurrence(Carbon $first, string $recurrence, int $count): Carbon
    {
        $daysBetweenOccurrences = $recurrence === 'daily' ? 1 : 7;

        return $first->copy()->addDays(($count - 1) * $daysBetweenOccurrences);
    }

    public static function maximumEndDate(Carbon $first): Carbon
    {
        return $first->copy()->addYear();
    }
}
