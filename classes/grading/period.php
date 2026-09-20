<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_zoomcustom\grading;

/**
 * Period grading for a single session Zoom activity.
 *
 * Attendance is graded against the duration configured on the Moodle activity,
 * and only the part of a student's attendance that falls inside the activity's
 * scheduled window counts:
 *
 *     window     = zoom.start_time .. zoom.start_time + zoom.duration
 *     counted    = sum of each attendance interval clipped to that window,
 *                  merged so overlapping or repeated intervals are not counted twice
 *     grade      = min(counted * grademax / zoom.duration, grademax)
 *
 * The denominator is always the configured duration. It never shrinks because
 * the real Zoom occurrence started late or ended early.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class period {

    /**
     * Recompute the denominator and the per user attendance for one occurrence.
     *
     * @param \stdClass $zoomrecord row of the zoom table
     * @param array $records rows of zoom_meeting_participants for this occurrence
     * @return \stdClass|null object with denominator and durations, or null when
     *                       the activity has no usable configured duration
     */
    public static function calculate(\stdClass $zoomrecord, array $records): ?\stdClass {
        $duration = (int) ($zoomrecord->duration ?? 0);
        $start = (int) ($zoomrecord->start_time ?? 0);

        if ($duration <= 0 || $start <= 0) {
            return null;
        }

        $end = $start + $duration;

        // Clip every interval to the activity window, keyed the same way the
        // upstream grading loop keys its users.
        $intervals = [];
        foreach ($records as $record) {
            $key = self::participant_key($record);

            $from = max((int) $record->join_time, $start);
            $to = min((int) $record->leave_time, $end);
            if ($to <= $from) {
                // Entirely outside the window, or a zero length record.
                if (!isset($intervals[$key])) {
                    $intervals[$key] = [];
                }
                continue;
            }

            $intervals[$key][] = [$from, $to];
        }

        $durations = [];
        foreach ($intervals as $key => $list) {
            $durations[$key] = min(self::merge_and_sum($list), $duration);
        }

        return (object) [
            'denominator' => $duration,
            'durations' => $durations,
            'windowstart' => $start,
            'windowend' => $end,
        ];
    }

    /**
     * Counted attendance for one set of raw intervals.
     *
     * Exposed for tests and for later reuse.
     *
     * @param int $activitystart
     * @param int $activityduration
     * @param array $rawintervals list of [join, leave]
     * @return int counted seconds, capped at the activity duration
     */
    public static function counted_duration(int $activitystart, int $activityduration, array $rawintervals): int {
        if ($activityduration <= 0) {
            return 0;
        }

        $end = $activitystart + $activityduration;
        $clipped = [];
        foreach ($rawintervals as $interval) {
            [$join, $leave] = $interval;
            $from = max((int) $join, $activitystart);
            $to = min((int) $leave, $end);
            if ($to > $from) {
                $clipped[] = [$from, $to];
            }
        }

        return min(self::merge_and_sum($clipped), $activityduration);
    }

    /**
     * Merge overlapping or touching intervals and sum their lengths.
     *
     * @param array $intervals list of [start, end] with end > start
     * @return int
     */
    public static function merge_and_sum(array $intervals): int {
        if (empty($intervals)) {
            return 0;
        }

        usort($intervals, function(array $a, array $b): int {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        $total = 0;
        $currentstart = $intervals[0][0];
        $currentend = $intervals[0][1];

        foreach (array_slice($intervals, 1) as $interval) {
            if ($interval[0] <= $currentend) {
                // Overlapping or adjacent: extend, never add twice.
                $currentend = max($currentend, $interval[1]);
                continue;
            }
            $total += $currentend - $currentstart;
            $currentstart = $interval[0];
            $currentend = $interval[1];
        }

        return $total + ($currentend - $currentstart);
    }

    /**
     * The array key upstream uses for a participant row.
     *
     * This mirrors mod_zoom exactly, including the numeric name guard, so the
     * durations we hand back are keyed the way the upstream grading loop
     * expects. A numeric string key becomes an integer key in PHP, which is
     * what the upstream is_integer() check relies on.
     *
     * @param \stdClass $record
     * @return string|int
     */
    public static function participant_key(\stdClass $record) {
        $userid = $record->userid ?? null;
        if (empty($userid)) {
            if (is_numeric($record->name)) {
                return '~' . $record->name . '~';
            }
            return $record->name;
        }
        return $userid;
    }

    /**
     * The grade this policy would award, used by tests and by the verification page.
     *
     * @param int $counted counted seconds
     * @param int $activityduration configured seconds
     * @param float $grademax
     * @return float
     */
    public static function grade(int $counted, int $activityduration, float $grademax): float {
        if ($activityduration <= 0) {
            return 0.0;
        }
        return min($counted * $grademax / $activityduration, $grademax);
    }
}
