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

namespace local_zoomcustom\recurring;

/**
 * Who a recurring Zoom activity is graded for.
 *
 * Deliberately not built from the participant rows: a student who never joined
 * must still be graded, and can only be found through enrolment.
 *
 * There is no group dimension here. mod_zoom declares FEATURE_GROUPINGS but not
 * FEATURE_GROUPS and has no per-group meeting concept, so there is nothing
 * group-specific to resolve. What actually gates access is enrolment plus the
 * activity's availability conditions - which is where group and grouping
 * restrictions live anyway, alongside date, grade and profile ones, and which
 * info_module applies for the section as well as the module.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class users {

    /**
     * The users a zoom activity should produce recurring grades for.
     *
     * @param \stdClass $zoomrecord row of the zoom table
     * @return int[] user ids, keyed by user id
     */
    public static function gradeable(\stdClass $zoomrecord): array {
        global $CFG;

        require_once($CFG->dirroot . '/grade/lib.php');

        $cm = get_coursemodule_from_instance('zoom', $zoomrecord->id, $zoomrecord->course, false, IGNORE_MISSING);
        if (!$cm) {
            // The activity is gone or half deleted; grade nobody rather than
            // guessing at a cohort.
            return [];
        }

        // Gradebook roles only - not get_enrolled_users(), which would include
        // teachers. Suspended enrolments are excluded: a suspended student is
        // not attending, and writing them a zero would be noise rather than a
        // grade.
        $users = get_gradable_users((int) $zoomrecord->course, null, true);

        if (empty($users)) {
            return [];
        }

        $modinfo = get_fast_modinfo((int) $zoomrecord->course);
        $cminfo = $modinfo->get_cm($cm->id);

        // One pass, covering every availability condition on both the section
        // and the module.
        $users = (new \core_availability\info_module($cminfo))->filter_user_list($users);

        $userids = [];
        foreach (array_keys($users) as $userid) {
            $userids[(int) $userid] = (int) $userid;
        }

        return $userids;
    }
}
