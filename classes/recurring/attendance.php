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

use local_zoomcustom\grading\period;

/**
 * Counted attendance for one occurrence, and the cache of it.
 *
 * The counting rule is 001's, unchanged: clip each interval to the window,
 * merge overlaps so a rejoin is not counted twice, cap at the window length.
 * Only the window differs - 001 uses the activity's own start, while a
 * recurring occurrence uses its own actual start with the activity's
 * configured duration, so the denominator still never shrinks because a
 * session ran short.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance {

    /**
     * Counted seconds per user for one occurrence.
     *
     * Every gradeable user appears in the result. A user with no participant
     * rows counts zero, which is the whole point: absence is a grade, not a
     * missing row.
     *
     * Participant rows that cannot be resolved to a Moodle user are ignored.
     * mod_zoom reports those separately for manual grading, and this has no
     * better way to guess who they were.
     *
     * @param \stdClass $zoomrecord row of the zoom table
     * @param \stdClass $details row of zoom_meeting_details
     * @param int[] $userids gradeable users, as returned by users::gradeable()
     * @return int[] counted seconds keyed by user id
     */
    public static function calculate(\stdClass $zoomrecord, \stdClass $details, array $userids): array {
        global $DB;

        $counted = [];
        foreach ($userids as $userid) {
            $counted[$userid] = 0;
        }

        if (empty($userids)) {
            return $counted;
        }

        $windowstart = (int) $details->start_time;
        $duration = (int) $zoomrecord->duration;
        if ($windowstart <= 0 || $duration <= 0) {
            return $counted;
        }

        $records = $DB->get_records('zoom_meeting_participants', ['detailsid' => (int) $details->id],
                'join_time ASC', 'id, userid, name, join_time, leave_time');

        // Keyed exactly as 001 keys them. A numeric user id becomes an integer
        // array key, while a participant mod_zoom could not match to an account
        // keeps its name as a string key - which is how the two are told apart
        // below, since the database hands back every column as a string.
        $intervals = [];
        foreach ($records as $record) {
            $intervals[period::participant_key($record)][] =
                    [(int) $record->join_time, (int) $record->leave_time];
        }

        foreach ($intervals as $userid => $list) {
            // Unidentified participants, and anyone outside the graded cohort,
            // have no grade to receive here.
            if (!is_int($userid) || !isset($counted[$userid])) {
                continue;
            }
            $counted[$userid] = period::counted_duration($windowstart, $duration, $list);
        }

        return $counted;
    }

    /**
     * Replace the cached attendance for one occurrence.
     *
     * @param int $occurrenceid
     * @param int[] $counted counted seconds keyed by user id
     * @return void
     */
    public static function store(int $occurrenceid, array $counted): void {
        global $DB;

        $now = time();
        $existing = $DB->get_records('local_zoomcustom_attendance',
                ['occurrenceid' => $occurrenceid], '', 'userid, id, countedseconds');

        $insert = [];
        foreach ($counted as $userid => $seconds) {
            $seconds = (int) $seconds;

            if (!isset($existing[$userid])) {
                $insert[] = (object) [
                    'occurrenceid' => $occurrenceid,
                    'userid' => (int) $userid,
                    'countedseconds' => $seconds,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                continue;
            }

            $row = $existing[$userid];
            unset($existing[$userid]);

            // Leave unchanged rows alone so timemodified stays meaningful.
            if ((int) $row->countedseconds !== $seconds) {
                $DB->update_record('local_zoomcustom_attendance', (object) [
                    'id' => $row->id,
                    'countedseconds' => $seconds,
                    'timemodified' => $now,
                ]);
            }
        }

        if ($insert) {
            $DB->insert_records('local_zoomcustom_attendance', $insert);
        }

        // Anyone no longer gradeable here (unenrolled, or newly excluded by an
        // availability condition) should not keep a stale cached row.
        if ($existing) {
            $DB->delete_records_list('local_zoomcustom_attendance', 'id',
                    array_column($existing, 'id'));
        }
    }

    /**
     * The cached attendance for one occurrence.
     *
     * @param int $occurrenceid
     * @return int[] counted seconds keyed by user id
     */
    public static function cached(int $occurrenceid): array {
        global $DB;

        $rows = $DB->get_records('local_zoomcustom_attendance',
                ['occurrenceid' => $occurrenceid], '', 'userid, countedseconds');

        $counted = [];
        foreach ($rows as $userid => $row) {
            $counted[(int) $userid] = (int) $row->countedseconds;
        }

        return $counted;
    }
}
