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
 * Stops upstream Zoom grading from running while the period grading
 * customisation is not active.
 *
 * The failure this exists to prevent is: mod_zoom is updated, the patch
 * disappears, the stock task runs, and inflated grades are written that the
 * fix can never lower again, because upstream only ever raises a grade.
 *
 * Pausing the report task loses nothing. Upstream resumes from its own
 * last_call_made_at checkpoint, within Zoom's 30 day reporting window.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard {

    /** @var string Only an applied and verified customisation is safe. */
    public const MODE_VERIFIED = 'verified_only';

    /** @var string An applied but not yet verified customisation is accepted. */
    public const MODE_APPLIED = 'applied_ok';

    /** @var string The upstream task this guard controls. */
    public const ZOOM_TASK = '\mod_zoom\task\get_meeting_reports';

    /** @var \stdClass|null Per request cache of the read only evaluation. */
    protected static $cache = null;

    /**
     * Work out whether Zoom grading may run, and optionally enforce it.
     *
     * @param bool $enforce pause or resume the upstream task and record the window
     * @return \stdClass safe, state, reason, patchstatus, enforced, taskpaused, pausedbyus
     */
    public static function evaluate(bool $enforce = false): \stdClass {
        if (!$enforce && self::$cache !== null) {
            return self::$cache;
        }

        $result = (object) [
            'safe' => false,
            'state' => null,
            'reason' => '',
            'patchstatus' => null,
            'enforced' => false,
            'taskpaused' => self::task_disabled(),
            'pausedbyus' => (bool) get_config('local_zoomcustom', 'taskpausedbyus'),
            'acknowledged' => false,
        ];

        if (!class_exists('\local_patchmanager\api')) {
            $result->reason = get_string('guardnoengine', 'local_zoomcustom');
        } else {
            $status = \local_patchmanager\api::get_status('local_zoomcustom', patches::ID);
            if ($status === null) {
                $result->reason = get_string('guardnotregistered', 'local_zoomcustom');
            } else {
                $result->patchstatus = $status;
                $result->state = $status->state;

                $mode = get_config('local_zoomcustom', 'strictness') ?: self::MODE_VERIFIED;
                $applied = ($status->state === \local_patchmanager\state::APPLIED);

                if ($status->acknowledgement !== null) {
                    $result->safe = true;
                    $result->acknowledged = true;
                    $result->reason = get_string('guardacknowledged', 'local_zoomcustom',
                            $status->acknowledgement->reason);
                } else if ($applied && ($status->verified || $mode === self::MODE_APPLIED)) {
                    $result->safe = true;
                    $result->reason = get_string('guardok', 'local_zoomcustom');
                } else if ($applied) {
                    $result->reason = get_string('guardunverified', 'local_zoomcustom');
                } else {
                    $result->reason = get_string('guardstate', 'local_zoomcustom', $status->state_label());
                }
            }
        }

        if (!$enforce) {
            self::$cache = $result;
            return $result;
        }

        // Enforcement is on unless an administrator explicitly turned it off.
        $enabled = get_config('local_zoomcustom', 'guardenabled');
        if ($enabled === false || (string) $enabled === '1') {
            $result->enforced = true;
            if ($result->safe) {
                self::resume($result);
            } else {
                self::pause($result);
            }
        }

        self::record_window($result);
        self::$cache = $result;

        return $result;
    }

    /**
     * Read only check used by the console guard and by the status card.
     *
     * @return bool
     */
    public static function is_safe(): bool {
        return self::evaluate(false)->safe;
    }

    /**
     * Throw unless Zoom grading may run.
     *
     * @return void
     * @throws \moodle_exception
     */
    public static function require_safe(): void {
        $result = self::evaluate(false);
        if ($result->safe) {
            return;
        }

        throw new \moodle_exception('guardblocked', 'local_zoomcustom',
                new \moodle_url('/local/patchmanager/index.php'), $result->reason);
    }

    /**
     * Is the upstream task currently disabled?
     *
     * @return bool|null null when mod_zoom or the task is not available
     */
    public static function task_disabled(): ?bool {
        $task = self::get_task();
        return $task === null ? null : (bool) $task->get_disabled();
    }

    /**
     * Windows during which the customisation was not active.
     *
     * Phase 2 can use these to find activities that may have been graded by the
     * upstream calculation through a path the guard could not control.
     *
     * @param int $since only windows that ended after this time, 0 for all
     * @return \stdClass[]
     */
    public static function unsafe_windows(int $since = 0): array {
        global $DB;

        if ($since > 0) {
            return $DB->get_records_select('local_zoomcustom_guard',
                    'timeend IS NULL OR timeend >= :since', ['since' => $since], 'timestart ASC');
        }

        return $DB->get_records('local_zoomcustom_guard', [], 'timestart ASC');
    }

    /**
     * Give the upstream task back to Moodle.
     *
     * Used when this plugin is uninstalled, so a pause we applied never
     * outlives the plugin that applied it.
     *
     * @return void
     */
    public static function release(): void {
        $result = (object) ['taskpaused' => null, 'pausedbyus' => null];
        self::resume($result);
    }

    /**
     * Pause the upstream task, unless an administrator already disabled it.
     *
     * @param \stdClass $result
     * @return void
     */
    protected static function pause(\stdClass $result): void {
        $task = self::get_task();
        if ($task === null) {
            return;
        }

        if ($task->get_disabled()) {
            // Already off. If we did not do it, leave the administrator's own
            // configuration completely alone.
            $result->taskpaused = true;
            return;
        }

        $task->set_disabled(true);
        \core\task\manager::configure_scheduled_task($task);
        set_config('taskpausedbyus', 1, 'local_zoomcustom');

        $result->taskpaused = true;
        $result->pausedbyus = true;
    }

    /**
     * Resume the upstream task, but only if this plugin paused it.
     *
     * @param \stdClass $result
     * @return void
     */
    protected static function resume(\stdClass $result): void {
        if (!get_config('local_zoomcustom', 'taskpausedbyus')) {
            return;
        }

        $task = self::get_task();
        if ($task !== null && $task->get_disabled()) {
            $task->set_disabled(false);
            \core\task\manager::configure_scheduled_task($task);
        }

        unset_config('taskpausedbyus', 'local_zoomcustom');

        $result->taskpaused = false;
        $result->pausedbyus = false;
    }

    /**
     * Open or close the record of an unsafe period.
     *
     * @param \stdClass $result
     * @return void
     */
    protected static function record_window(\stdClass $result): void {
        global $DB;

        $open = $DB->get_records_select('local_zoomcustom_guard', 'timeend IS NULL', [], 'timestart DESC', '*', 0, 1);
        $open = $open ? reset($open) : null;

        if (!$result->safe) {
            if ($open === null) {
                $host = gethostname();
                $DB->insert_record('local_zoomcustom_guard', (object) [
                    'timestart' => time(),
                    'timeend' => null,
                    'state' => (string) ($result->state ?? 'unknown'),
                    'reason' => \core_text::substr($result->reason, 0, 255),
                    'taskpaused' => $result->taskpaused ? 1 : 0,
                    'hostname' => $host === false ? 'unknown' : $host,
                ]);
            }
            return;
        }

        if ($open !== null) {
            $open->timeend = time();
            $DB->update_record('local_zoomcustom_guard', $open);
        }
    }

    /**
     * The upstream scheduled task object, or null when mod_zoom is absent.
     *
     * @return \core\task\scheduled_task|null
     */
    protected static function get_task(): ?\core\task\scheduled_task {
        if (!class_exists('\mod_zoom\task\get_meeting_reports')) {
            return null;
        }
        $task = \core\task\manager::get_scheduled_task(self::ZOOM_TASK);
        return $task ?: null;
    }

    /**
     * Forget the per request cache. Used by tests.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$cache = null;
    }
}
