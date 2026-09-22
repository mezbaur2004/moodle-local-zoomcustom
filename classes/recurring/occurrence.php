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
 * Discovery and lifecycle state of one occurrence of a recurring Zoom meeting.
 *
 * Occurrences are discovered from the zoom tables, never from the patch: the
 * scheduled task alone is authoritative, so an unapplied 002 changes when an
 * occurrence is noticed, not whether it is.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class occurrence {

    /** @var string Discovered, but not yet calculated: the settle delay has not elapsed. */
    public const PENDING = 'pending';

    /** @var string Calculated and cached, still inside the reconciliation window. */
    public const SETTLED = 'settled';

    /** @var string Reconciliation closed without a conflicting change. Never recalculated. */
    public const FINAL = 'final';

    /** @var string No usable data ever arrived. Excluded from the denominator, kept for review. */
    public const EXPIRED = 'expired';

    /** @var string Reconciliation found a conflicting change. Still counted, awaiting review. */
    public const FLAGGED = 'flagged';

    /** @var int Default wait after an occurrence's window before trusting its data. */
    public const DEFAULT_SETTLE_DELAY = 1800;

    /** @var int Default period a settled occurrence stays open to recalculation. */
    public const DEFAULT_RECONCILIATION_WINDOW = 259200;

    /** @var int Default period to wait for any usable data before giving up. */
    public const DEFAULT_MAX_WAIT = 604800;

    /**
     * @var int mod_zoom's ZOOM_RECURRINGTYPE_NOTIME.
     *
     * Declared here rather than required from mod_zoom's locallib.php, which is
     * not loaded on every request that reaches this code.
     */
    public const RECURRINGTYPE_NOTIME = 0;

    /**
     * Whether a zoom activity is in scope for recurring grading.
     *
     * A meeting with no fixed time has no schedule and therefore no duration to
     * grade against, so it is skipped for the same reason 001 skips it.
     *
     * @param \stdClass $zoomrecord row of the zoom table
     * @return bool
     */
    public static function in_scope(\stdClass $zoomrecord): bool {
        if (empty($zoomrecord->recurring)) {
            return false;
        }
        if ((int) ($zoomrecord->recurrence_type ?? self::RECURRINGTYPE_NOTIME) === self::RECURRINGTYPE_NOTIME) {
            return false;
        }
        return (int) ($zoomrecord->duration ?? 0) > 0;
    }

    /**
     * Record one occurrence as pending, if it is not already known.
     *
     * Existing rows are left untouched: advancing the lifecycle is the task's
     * job, and this is also called from inside mod_zoom's own transaction where
     * doing less is safer.
     *
     * @param \stdClass $zoomrecord row of the zoom table
     * @param int $detailsid zoom_meeting_details.id for this occurrence
     * @return bool whether a row was created
     */
    public static function note(\stdClass $zoomrecord, int $detailsid): bool {
        global $DB;

        if ($detailsid <= 0 || !self::in_scope($zoomrecord)) {
            return false;
        }

        if ($DB->record_exists('local_zoomcustom_occurrence', ['detailsid' => $detailsid])) {
            return false;
        }

        $now = time();
        $DB->insert_record('local_zoomcustom_occurrence', (object) [
            'zoomid' => (int) $zoomrecord->id,
            'detailsid' => $detailsid,
            'status' => self::PENDING,
            'participantsig' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return true;
    }

    /**
     * Discover occurrences of in-scope activities that are not yet recorded.
     *
     * @return int how many occurrences were recorded
     */
    public static function discover(): int {
        global $DB;

        $sql = "SELECT zmd.id AS detailsid, z.id AS zoomid
                  FROM {zoom_meeting_details} zmd
                  JOIN {zoom} z ON z.id = zmd.zoomid
             LEFT JOIN {local_zoomcustom_occurrence} o ON o.detailsid = zmd.id
                 WHERE o.id IS NULL
                       AND z.recurring > 0
                       AND z.recurrence_type <> :notime
                       AND z.duration > 0
                       AND zmd.end_time > 0";

        $rows = $DB->get_records_sql($sql, ['notime' => self::RECURRINGTYPE_NOTIME]);
        if (empty($rows)) {
            return 0;
        }

        $now = time();
        $records = [];
        foreach ($rows as $row) {
            $records[] = (object) [
                'zoomid' => (int) $row->zoomid,
                'detailsid' => (int) $row->detailsid,
                'status' => self::PENDING,
                'participantsig' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }

        $DB->insert_records('local_zoomcustom_occurrence', $records);

        return count($records);
    }

    /**
     * A signature of the participant rows an occurrence was calculated from.
     *
     * zoom_meeting_participants has no timemodified column, so there is nothing
     * to compare timestamps against. This covers the two ways the data can
     * change that matter: rows appearing, and intervals differing.
     *
     * @param int $detailsid
     * @return string
     */
    public static function signature(int $detailsid): string {
        global $DB;

        $rows = $DB->get_records(
            'zoom_meeting_participants',
            ['detailsid' => $detailsid],
            'id ASC',
            'id, userid, name, join_time, leave_time'
        );

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = implode(':', [
                (int) $row->id,
                (int) ($row->userid ?? 0),
                (string) ($row->name ?? ''),
                (int) $row->join_time,
                (int) $row->leave_time,
            ]);
        }

        return count($rows) . '-' . hash('sha256', implode('|', $parts));
    }

    /**
     * How long after an occurrence's scheduled end its data is trusted.
     *
     * @return int seconds
     */
    public static function settle_delay(): int {
        $value = get_config('local_zoomcustom', 'settledelay');
        return $value === false || $value === '' ? self::DEFAULT_SETTLE_DELAY : (int) $value;
    }

    /**
     * How long a settled occurrence stays open to recalculation.
     *
     * @return int seconds
     */
    public static function reconciliation_window(): int {
        $value = get_config('local_zoomcustom', 'reconciliationwindow');
        return $value === false || $value === '' ? self::DEFAULT_RECONCILIATION_WINDOW : (int) $value;
    }

    /**
     * How long to wait for usable data before giving up on an occurrence.
     *
     * @return int seconds
     */
    public static function max_wait(): int {
        $value = get_config('local_zoomcustom', 'maxwait');
        return $value === false || $value === '' ? self::DEFAULT_MAX_WAIT : (int) $value;
    }

    /**
     * Move every occurrence that is not yet final along its lifecycle.
     *
     * @param int|null $now override for tests
     * @return array counts keyed by the transition that happened
     */
    public static function advance(?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $counts = ['settled' => 0, 'final' => 0, 'expired' => 0, 'flagged' => 0, 'recalculated' => 0];

        $sql = "SELECT o.*, zmd.start_time, zmd.end_time, z.duration, z.course, z.id AS zoomrecordid
                  FROM {local_zoomcustom_occurrence} o
                  JOIN {zoom_meeting_details} zmd ON zmd.id = o.detailsid
                  JOIN {zoom} z ON z.id = o.zoomid
                 WHERE o.status <> :final AND o.status <> :expired";

        $rows = $DB->get_records_sql($sql, ['final' => self::FINAL, 'expired' => self::EXPIRED]);
        if (empty($rows)) {
            return $counts;
        }

        // One cohort lookup per activity, not per occurrence.
        $cohorts = [];

        foreach ($rows as $row) {
            $windowend = (int) $row->start_time + (int) $row->duration;

            if ($row->status === self::PENDING) {
                $transition = self::advance_pending($row, $windowend, $now, $cohorts);
            } else {
                // settled or flagged: both stay open to reconciliation.
                $transition = self::advance_settled($row, $now, $cohorts);
            }

            if ($transition !== null) {
                $counts[$transition]++;
            }
        }

        return $counts;
    }

    /**
     * A pending occurrence either settles, expires, or waits.
     *
     * @param \stdClass $row joined occurrence row
     * @param int $windowend occurrence start plus the activity's configured duration
     * @param int $now
     * @param array $cohorts per activity cache of gradeable users
     * @return string|null the transition that happened
     */
    protected static function advance_pending(\stdClass $row, int $windowend, int $now, array &$cohorts): ?string {
        $settleat = $windowend + self::settle_delay();

        if ($now < $settleat) {
            return null;
        }

        $signature = self::signature((int) $row->detailsid);
        $hasdata = !str_starts_with($signature, '0-');

        if (!$hasdata) {
            // Nothing to calculate from. Give the report task more chances
            // until the maximum wait runs out, then stop asking.
            if ($now >= $windowend + self::max_wait()) {
                self::set_status($row, self::EXPIRED, ['participantsig' => $signature]);
                return 'expired';
            }
            return null;
        }

        self::recalculate($row, $signature, $cohorts);
        self::set_status($row, self::SETTLED, [
            'participantsig' => $signature,
            'settledtime' => $now,
        ]);

        return 'settled';
    }

    /**
     * A settled or flagged occurrence reconciles, flags, or finalises.
     *
     * @param \stdClass $row joined occurrence row
     * @param int $now
     * @param array $cohorts per activity cache of gradeable users
     * @return string|null the transition that happened
     */
    protected static function advance_settled(\stdClass $row, int $now, array &$cohorts): ?string {
        $signature = self::signature((int) $row->detailsid);

        if ($signature !== (string) $row->participantsig) {
            $before = attendance::cached((int) $row->id);
            self::recalculate($row, $signature, $cohorts);
            $after = attendance::cached((int) $row->id);

            // Late data arriving is the ordinary case and needs no attention.
            // Attendance going *down* means the new data contradicts what was
            // already recorded, which a human should look at - so it is flagged,
            // while keeping the recalculated values and staying counted.
            $contradicted = false;
            foreach ($before as $userid => $seconds) {
                if (($after[$userid] ?? 0) < $seconds) {
                    $contradicted = true;
                    break;
                }
            }

            self::set_status($row, $contradicted ? self::FLAGGED : self::SETTLED, [
                'participantsig' => $signature,
            ]);

            return $contradicted ? 'flagged' : 'recalculated';
        }

        // A flagged occurrence waits for a person, never for the clock.
        if ($row->status === self::FLAGGED) {
            return null;
        }

        $settledtime = (int) ($row->settledtime ?: $now);
        if ($now < $settledtime + self::reconciliation_window()) {
            return null;
        }

        self::set_status($row, self::FINAL, ['finalizedtime' => $now]);

        return 'final';
    }

    /**
     * Recompute and cache the attendance of one occurrence.
     *
     * @param \stdClass $row joined occurrence row
     * @param string $signature the signature the calculation is based on
     * @param array $cohorts per activity cache of gradeable users
     * @return void
     */
    protected static function recalculate(\stdClass $row, string $signature, array &$cohorts): void {
        global $DB;

        $zoomid = (int) $row->zoomid;
        if (!array_key_exists($zoomid, $cohorts)) {
            $zoomrecord = $DB->get_record('zoom', ['id' => $zoomid]);
            $cohorts[$zoomid] = $zoomrecord ? users::gradeable($zoomrecord) : [];
        }

        $zoomrecord = (object) [
            'id' => $zoomid,
            'course' => (int) $row->course,
            'duration' => (int) $row->duration,
        ];
        $details = (object) [
            'id' => (int) $row->detailsid,
            'start_time' => (int) $row->start_time,
        ];

        attendance::store((int) $row->id,
                attendance::calculate($zoomrecord, $details, $cohorts[$zoomid]));
    }

    /**
     * Write a new status and whatever timestamps go with it.
     *
     * @param \stdClass $row joined occurrence row, updated in place
     * @param string $status
     * @param array $fields extra fields to set
     * @return void
     */
    protected static function set_status(\stdClass $row, string $status, array $fields = []): void {
        global $DB;

        $update = array_merge($fields, [
            'id' => (int) $row->id,
            'status' => $status,
            'timemodified' => time(),
        ]);

        $DB->update_record('local_zoomcustom_occurrence', (object) $update);

        foreach ($update as $name => $value) {
            $row->{$name} = $value;
        }
    }
}
