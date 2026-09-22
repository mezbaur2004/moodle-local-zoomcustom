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
 * Patch definitions handed to local_patchmanager.
 *
 * Both hunks only insert a guarded call. No upstream logic is copied or
 * rewritten, which is what keeps the patch surviving upstream changes.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class patches {

    /**
     * @var string Id of the period grading customisation.
     *
     * guard.php gates on this id alone. 002 is deliberately outside the guard:
     * it writes only to its own grade item and cannot corrupt mod_zoom's
     * itemnumber 0, so there is nothing for the guard to protect against, and
     * pausing the shared report task for it would stop 001's grading too.
     */
    public const ID = '001-period-grading';

    /** @var int Revision of the 001 definition. Bump it whenever a hunk changes. */
    public const REVISION = 1;

    /** @var string Id of the recurring grading customisation. */
    public const ID_RECURRING = '002-recurring-grading';

    /** @var int Revision of the 002 definition. Bump it whenever a hunk changes. */
    public const REVISION_RECURRING = 1;

    /** @var string Target component. */
    public const TARGET = 'mod_zoom';

    /** @var int The mod_zoom version this revision was written and tested against. */
    public const BASELINE = 2026082400;

    /**
     * Every customisation this pack provides.
     *
     * @return array
     */
    public static function all(): array {
        return [
            self::period_grading(),
            self::recurring_grading(),
        ];
    }

    /**
     * 001-period-grading.
     *
     * @return array
     */
    public static function period_grading(): array {
        return [
            'contract' => 1,
            'id' => self::ID,
            'name' => get_string('patch001name', 'local_zoomcustom'),
            'description' => get_string('patch001description', 'local_zoomcustom'),
            'component' => self::TARGET,
            'revision' => self::REVISION,
            'required' => true,
            'dependencies' => [],
            'testedversions' => [self::BASELINE],
            'baselinehashes' => [
                'classes/task/get_meeting_reports.php'
                    => 'f1c441d5acbf87dece0995b4f0b1629bee8b15ae7446646b25ee45c29172609e',
                'console/get_meeting_report.php'
                    => '0187eed7ecf6b150d2cd0c1e98ab801238a1d1fc5cd3ace13c03174988b0b2ea',
            ],
            'files' => [
                'classes/task/get_meeting_reports.php' => [
                    [
                        'type' => 'insert_before',
                        'label' => get_string('patch001hunk1', 'local_zoomcustom'),
                        'anchor' => self::grading_anchor(),
                        'payload' => self::grading_payload(),
                    ],
                ],
                'console/get_meeting_report.php' => [
                    [
                        'type' => 'insert_after',
                        'label' => get_string('patch001hunk2', 'local_zoomcustom'),
                        'anchor' => self::console_anchor(),
                        'payload' => self::console_payload(),
                    ],
                ],
            ],
        ];
    }

    /**
     * 002-recurring-grading.
     *
     * Independent of 001: it targets a different function of the same file, so
     * the two hunks can never overlap and may be applied in either order.
     *
     * The hunk is a latency nudge, not the grading path. Recurring grades are
     * computed and written by this pack's own scheduled task, which reads only
     * the zoom tables and writes only through gradelib. The customisation exists
     * so a freshly ingested occurrence is picked up immediately instead of
     * waiting for the next cron run; with it unapplied the task still produces
     * the same grades, just later.
     *
     * Not marked required for that reason, and deliberately outside the guard
     * (see the note on self::ID).
     *
     * @return array
     */
    public static function recurring_grading(): array {
        return [
            'contract' => 1,
            'id' => self::ID_RECURRING,
            'name' => get_string('patch002name', 'local_zoomcustom'),
            'description' => get_string('patch002description', 'local_zoomcustom'),
            'component' => self::TARGET,
            'revision' => self::REVISION_RECURRING,
            'required' => false,
            'dependencies' => [],
            'testedversions' => [self::BASELINE],
            'baselinehashes' => [
                'classes/task/get_meeting_reports.php'
                    => 'f1c441d5acbf87dece0995b4f0b1629bee8b15ae7446646b25ee45c29172609e',
            ],
            'files' => [
                'classes/task/get_meeting_reports.php' => [
                    [
                        'type' => 'insert_before',
                        'label' => get_string('patch002hunk1', 'local_zoomcustom'),
                        'anchor' => self::occurrence_anchor(),
                        'payload' => self::occurrence_payload(),
                    ],
                ],
            ],
        ];
    }

    /**
     * Upstream context in process_meeting_reports(), the commit that ends the
     * transaction in which the occurrence and its participant rows were written.
     *
     * Anchoring here means the nudge sees data that is complete for this
     * occurrence, and rolls back with it if the transaction fails.
     *
     * @return string
     */
    protected static function occurrence_anchor(): string {
        return '            $transaction->allow_commit();';
    }

    /**
     * The block inserted before that context.
     *
     * @return string
     */
    protected static function occurrence_payload(): string {
        $block = <<<'PAYLOAD'
            // BEGIN local_zoomcustom:002-recurring-grading r1
            if (class_exists('\local_zoomcustom\hook')) {
                \local_zoomcustom\hook::recurring_occurrence($zoomrecord, $detailsid);
            }
            // END local_zoomcustom:002-recurring-grading r1
PAYLOAD;

        // One blank line so the commit keeps its spacing.
        return $block . "\n\n";
    }

    /**
     * Upstream context in grading_participant_upon_duration(), immediately after
     * the loop that builds $durations and immediately before the grading loop.
     *
     * @return string
     */
    protected static function grading_anchor(): string {
        return <<<'ANCHOR'
        // Used to count the number of users being graded.
        $graded = 0;
ANCHOR;
    }

    /**
     * The block inserted before that context.
     *
     * @return string
     */
    protected static function grading_payload(): string {
        $block = <<<'PAYLOAD'
        // BEGIN local_zoomcustom:001-period-grading r1
        if (class_exists('\local_zoomcustom\hook')) {
            \local_zoomcustom\hook::period_durations($zoomrecord, $records, $meetingduration, $durations);
        }
        // END local_zoomcustom:001-period-grading r1
PAYLOAD;

        // One blank line so the upstream comment keeps its spacing.
        return $block . "\n\n";
    }

    /**
     * Upstream context in console/get_meeting_report.php, right after the
     * capability check and before anything is echoed.
     *
     * @return string
     */
    protected static function console_anchor(): string {
        return "require_capability('mod/zoom:refreshsessions', \$context);";
    }

    /**
     * The guard inserted after that context.
     *
     * @return string
     */
    protected static function console_payload(): string {
        $block = <<<'PAYLOAD'
// BEGIN local_zoomcustom:001-period-grading r1
if (class_exists('\local_zoomcustom\hook')) {
    \local_zoomcustom\hook::require_grading_safe();
}
// END local_zoomcustom:001-period-grading r1
PAYLOAD;

        return "\n\n" . $block;
    }
}
