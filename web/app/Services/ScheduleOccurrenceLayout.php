<?php

namespace App\Services;

use App\Support\Occurrence;
use Illuminate\Support\Collection;

class ScheduleOccurrenceLayout
{
    /**
     * Assign side-by-side lanes to overlapping occurrences in a single day.
     *
     * @param  Collection<int, Occurrence>  $occurrences
     * @return Collection<int, Occurrence>
     */
    public function assignLanes(Collection $occurrences): Collection
    {
        $sorted = $occurrences
            ->sortBy(fn (Occurrence $occurrence) => $occurrence->start->getTimestamp())
            ->values();
        $clusters = [];
        $cluster = [];
        $clusterEnd = null;

        foreach ($sorted as $occurrence) {
            if ($cluster !== [] && $occurrence->start->greaterThanOrEqualTo($clusterEnd)) {
                $clusters[] = $cluster;
                $cluster = [];
                $clusterEnd = null;
            }

            $cluster[] = $occurrence;
            if ($clusterEnd === null || $occurrence->end->greaterThan($clusterEnd)) {
                $clusterEnd = $occurrence->end;
            }
        }
        if ($cluster !== []) {
            $clusters[] = $cluster;
        }

        $laidOut = [];
        foreach ($clusters as $cluster) {
            $laneEnds = [];
            $placements = [];

            foreach ($cluster as $occurrence) {
                $lane = null;
                foreach ($laneEnds as $index => $laneEnd) {
                    if ($laneEnd->lessThanOrEqualTo($occurrence->start)) {
                        $lane = $index;
                        break;
                    }
                }
                if ($lane === null) {
                    $lane = count($laneEnds);
                }

                $laneEnds[$lane] = $occurrence->end;
                $placements[] = [$occurrence, $lane];
            }

            $laneCount = count($laneEnds);
            foreach ($placements as [$occurrence, $lane]) {
                $laidOut[] = new Occurrence(
                    $occurrence->schedule,
                    $occurrence->start,
                    $occurrence->end,
                    $occurrence->outcome,
                    $lane,
                    $laneCount,
                );
            }
        }

        return collect($laidOut);
    }
}
