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

namespace local_zoomcustom;

/**
 * The entry points that patch 001 inserts into mod_zoom.
 *
 * Everything upstream calls goes through this class, which keeps the patch
 * itself to a few lines. If this plugin is missing, the guarded call in
 * mod_zoom is skipped and upstream behaves exactly as shipped.
 *
 * If this code throws, the exception propagates on purpose: the calling task
 * fails and is retried, instead of silently writing grades from the upstream
 * calculation this customisation exists to correct.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook {

    /**
     * Replace the denominator and the per user attendance used by period grading.
     *
     * Called from mod_zoom\task\get_meeting_reports::grading_participant_upon_duration()
     * after upstream has collected the participant records, and before it writes
     * any grade. Upstream keeps responsibility for writing grades, for its
     * "only raise a grade" rule and for notifying teachers.
     *
     * @param \stdClass $zoomrecord row of the zoom table
     * @param array $records rows of zoom_meeting_participants for this occurrence
     * @param mixed $meetingduration denominator, replaced by reference
     * @param mixed $durations per user attendance, replaced by reference
     * @return void
     */
    public static function period_durations(\stdClass $zoomrecord, array $records,
            &$meetingduration, &$durations): void {
        heartbeat::record('period_durations');

        if (!empty($zoomrecord->recurring)) {
            // Recurring meetings are out of scope for this revision: leave the
            // upstream calculation untouched.
            return;
        }

        $result = grading\period::calculate($zoomrecord, $records);

        if ($result === null) {
            // The activity has no usable configured duration. Grade nobody
            // rather than grading against a meaningless denominator.
            debugging('local_zoomcustom: zoom activity ' . ($zoomrecord->id ?? '?')
                    . ' has no usable duration, skipping period grading', DEBUG_DEVELOPER);
            $durations = [];
            return;
        }

        $meetingduration = $result->denominator;
        $durations = $result->durations;
    }

    /**
     * Refuse to run the report/grading path while the customisation is not safe.
     *
     * Called from mod_zoom/console/get_meeting_report.php, which runs the report
     * task directly and therefore bypasses the scheduled task's disabled state.
     *
     * @return void
     * @throws \moodle_exception
     */
    public static function require_grading_safe(): void {
        guard::require_safe();
    }
}
