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

/**
 * Show what period grading would award for one Zoom activity, next to what the
 * upstream calculation produces.
 *
 * Read only: it never writes a grade, so it is safe to run on production.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');

use local_zoomcustom\grading\period;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'zoomid' => 0, 'cmid' => 0],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknownoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help'] || (!$options['zoomid'] && !$options['cmid'])) {
    cli_writeln("Compare period grading with the upstream calculation for one Zoom activity.

Options:
  -h, --help       Print this help.
      --zoomid=N   Row id in the zoom table.
      --cmid=N     Course module id, used instead of --zoomid.

Nothing is written. Grades are only printed.

Example:
  php local/zoomcustom/cli/verify_activity.php --zoomid=54
");
    exit(0);
}

if ($options['cmid']) {
    $cm = get_coursemodule_from_id('zoom', (int) $options['cmid'], 0, false, MUST_EXIST);
    $zoomid = (int) $cm->instance;
} else {
    $zoomid = (int) $options['zoomid'];
}

$zoom = $DB->get_record('zoom', ['id' => $zoomid], '*', MUST_EXIST);

$windowstart = (int) $zoom->start_time;
$duration = (int) $zoom->duration;
$windowend = $windowstart + $duration;

cli_writeln('Activity: ' . $zoom->name);
cli_writeln('  zoom.id           : ' . $zoom->id);
cli_writeln('  grading method    : ' . ($zoom->grading_method ?: '(site default)'));
cli_writeln('  recurring         : ' . ($zoom->recurring ? 'yes' : 'no'));
cli_writeln('  configured window : ' . userdate($windowstart) . ' -> ' . userdate($windowend)
        . ' (' . $duration . ' seconds)');

$gradelist = grade_get_grades($zoom->course, 'mod', 'zoom', $zoom->id);
$grademax = !empty($gradelist->items) ? (float) $gradelist->items[0]->grademax : 100.0;
cli_writeln('  grade maximum     : ' . $grademax);
cli_writeln('');

$details = $DB->get_records('zoom_meeting_details', ['zoomid' => $zoom->id], 'start_time ASC');
if (!$details) {
    cli_writeln('No meeting occurrences recorded for this activity.');
    exit(0);
}

foreach ($details as $detail) {
    cli_writeln('Occurrence ' . $detail->id . ' (uuid ' . $detail->uuid . ')');
    cli_writeln('  real session: ' . userdate($detail->start_time) . ' -> ' . userdate($detail->end_time));

    $records = $DB->get_records('zoom_meeting_participants', ['detailsid' => $detail->id], 'join_time ASC');
    if (!$records) {
        cli_writeln('  no participant records');
        cli_writeln('');
        continue;
    }

    // What upstream would use as its denominator.
    $stockend = min((int) $detail->end_time, $windowend);
    $stockstart = max((int) $detail->start_time, $windowstart);
    $stockdenominator = $stockend - $stockstart;

    $result = period::calculate($zoom, $records);
    if ($result === null) {
        cli_writeln('  the activity has no usable configured duration, period grading would skip it');
        cli_writeln('');
        continue;
    }

    cli_writeln('  denominator: this plugin ' . $result->denominator
            . 's (configured) vs upstream ' . $stockdenominator . 's (real session, clipped)');

    // Raw upstream numerator per user, for comparison only.
    $rawtotals = [];
    foreach ($records as $record) {
        $key = period::participant_key($record);
        $rawtotals[$key] = ($rawtotals[$key] ?? 0) + (int) $record->duration;
    }

    foreach ($result->durations as $key => $counted) {
        $name = is_int($key) ? ('userid ' . $key) : ('unmatched "' . $key . '"');
        $newgrade = period::grade((int) $counted, $result->denominator, $grademax);
        $raw = $rawtotals[$key] ?? 0;
        $stockgrade = $stockdenominator > 0 ? min($raw * $grademax / $stockdenominator, $grademax) : null;

        cli_writeln(sprintf('  %-28s counted %4ds -> %6.2f   (upstream: raw %4ds -> %s)',
                $name,
                $counted,
                $newgrade,
                $raw,
                $stockgrade === null ? 'n/a' : sprintf('%6.2f', $stockgrade)));

        foreach ($records as $record) {
            if (period::participant_key($record) !== $key) {
                continue;
            }
            cli_writeln(sprintf('      %s -> %s  (zoom duration %ds)',
                    userdate($record->join_time, '%H:%M:%S'),
                    userdate($record->leave_time, '%H:%M:%S'),
                    $record->duration));
        }
    }

    cli_writeln('');
}

cli_writeln('Nothing was written. Grades in the gradebook are unchanged by this script.');
exit(0);
