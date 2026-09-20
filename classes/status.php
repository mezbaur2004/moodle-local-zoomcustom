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
 * The small, stable summary that a dashboard consumes.
 *
 * Call summary() for the data or render_card() for ready made HTML. The full
 * management interface stays on the patch manager page.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status {

    /**
     * Everything a dashboard needs, in one object.
     *
     * @return \stdClass
     */
    public static function summary(): \stdClass {
        $guard = guard::evaluate(false);
        $patch = $guard->patchstatus;

        $summary = (object) [
            'component' => patches::TARGET,
            'versiondisk' => null,
            'versiondb' => null,
            'upgradepending' => false,
            'registered' => $patch !== null,
            'state' => $patch ? $patch->state : null,
            'statelabel' => $patch ? $patch->state_label() : get_string('guardnoengine', 'local_zoomcustom'),
            'verified' => $patch ? $patch->verified : false,
            'required' => $patch ? $patch->required : true,
            'acknowledged' => $guard->acknowledged,
            'revision' => patches::REVISION,
            'revisionondisk' => $patch ? $patch->appliedrevision : null,
            'guardsafe' => $guard->safe,
            'guardreason' => $guard->reason,
            'taskpaused' => $guard->taskpaused,
            'heartbeat' => null,
            'health' => 'error',
            'messages' => [],
            'url' => (new \moodle_url('/local/patchmanager/index.php'))->out(false),
        ];

        if ($patch !== null) {
            $summary->versiondisk = $patch->componentversion->versiondisk;
            $summary->versiondb = $patch->componentversion->versiondb;
            $summary->upgradepending = $patch->componentversion->upgradepending;
        }

        $applytime = ($patch && $patch->lastapply) ? (int) $patch->lastapply->timecreated : null;
        $summary->heartbeat = heartbeat::evaluate($applytime, self::zoom_task_last_run());

        // Health.
        if ($patch === null) {
            $summary->health = 'error';
            $summary->messages[] = $guard->reason;
        } else if ($patch->is_current() && $guard->safe && $summary->heartbeat->state !== 'stale') {
            $summary->health = 'ok';
        } else if ($summary->taskpaused || $patch->severity() === 'error') {
            $summary->health = 'error';
        } else {
            $summary->health = 'warning';
        }

        // Messages, most important first.
        if ($patch !== null && !$patch->is_current()) {
            $summary->messages[] = get_string('cardneedsreview', 'local_zoomcustom', $patch->state_label());
        }
        if ($summary->taskpaused) {
            $summary->messages[] = get_string('cardtaskpaused', 'local_zoomcustom');
        }
        if ($summary->acknowledged) {
            $summary->messages[] = get_string('cardacknowledged', 'local_zoomcustom');
        }
        if ($summary->heartbeat->state === 'stale') {
            $summary->messages[] = get_string('cardheartbeatstale', 'local_zoomcustom');
        }
        if ($summary->upgradepending) {
            $summary->messages[] = get_string('cardupgradepending', 'local_zoomcustom');
        }

        return $summary;
    }

    /**
     * A compact status card for an existing dashboard.
     *
     * Show it to site administrators only: it describes the state of the code.
     *
     * @return string HTML
     */
    public static function render_card(): string {
        $summary = self::summary();

        $symbols = ['ok' => '✓', 'warning' => '⚠', 'error' => '✗'];
        $classes = ['ok' => 'text-success', 'warning' => 'text-warning', 'error' => 'text-danger'];

        $out = \html_writer::start_tag('div', ['class' => 'local-zoomcustom-card card p-3']);
        $out .= \html_writer::tag('h5', get_string('cardtitle', 'local_zoomcustom'), ['class' => 'mb-1']);
        $out .= \html_writer::tag('div', $summary->component . ': ' . ($summary->versiondisk ?? '?'),
                ['class' => 'small text-muted mb-2']);

        if ($summary->health === 'ok') {
            $out .= \html_writer::tag('div', $symbols['ok'] . ' ' . get_string('cardallcurrent', 'local_zoomcustom'),
                    ['class' => $classes['ok']]);
        } else {
            $out .= \html_writer::tag('div',
                    $symbols[$summary->health] . ' ' . get_string('cardattention', 'local_zoomcustom'),
                    ['class' => $classes[$summary->health] . ' font-weight-bold']);
            foreach ($summary->messages as $message) {
                $out .= \html_writer::tag('div', s($message), ['class' => 'small']);
            }
        }

        $out .= \html_writer::tag('div',
                get_string('cardpatchline', 'local_zoomcustom', (object) [
                    'name' => get_string('patch001name', 'local_zoomcustom'),
                    'state' => $summary->statelabel,
                    'verified' => $summary->verified
                        ? get_string('verified', 'local_patchmanager')
                        : get_string('unverified', 'local_patchmanager'),
                ]), ['class' => 'small mt-2']);

        if ($summary->heartbeat->time !== null) {
            $out .= \html_writer::tag('div',
                    get_string('cardheartbeat', 'local_zoomcustom', format_time($summary->heartbeat->age)),
                    ['class' => 'small text-muted']);
        }

        $out .= \html_writer::tag('div',
                \html_writer::link($summary->url, get_string('cardreview', 'local_zoomcustom'),
                        ['class' => 'btn btn-secondary btn-sm mt-2']));

        $out .= \html_writer::end_tag('div');

        return $out;
    }

    /**
     * When the upstream report task last ran.
     *
     * @return int|null
     */
    protected static function zoom_task_last_run(): ?int {
        if (!class_exists('\mod_zoom\task\get_meeting_reports')) {
            return null;
        }
        $task = \core\task\manager::get_scheduled_task(guard::ZOOM_TASK);
        if (!$task) {
            return null;
        }
        $last = $task->get_last_run_time();
        return $last ? (int) $last : null;
    }
}
