<?php

namespace App\Support;

use App\Models\Schedule;
use Illuminate\Support\Carbon;

/**
 * One concrete appearance of a schedule entry on the calendar.
 * A recurring entry produces one occurrence per matching day.
 */
final readonly class Occurrence
{
    public function __construct(
        public Schedule $schedule,
        public Carbon $start,
        public Carbon $end,
    ) {}
}
