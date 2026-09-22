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

namespace local_zoomcustom\task;

use local_zoomcustom\recurring\occurrence;

/**
 * Maintain recurring attendance grades.
 *
 * This task, not the 002 customisation, is what makes recurring grading
 * correct. It reads the zoom tables and this pack's own, and writes grades only
 * through gradelib, so it produces the same result whether or not 002 is
 * applied to mod_zoom. Applying 002 only shortens the delay before a new
 * occurrence is noticed.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recurring_grading extends \core\task\scheduled_task {

    /**
     * Name shown in the scheduled task admin screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrecurringgrading', 'local_zoomcustom');
    }

    /**
     * Discover occurrences of recurring meetings that are not yet tracked.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        // mod_zoom is a declared dependency, but a half finished upgrade should
        // skip a cron run rather than fail it.
        $tables = $DB->get_manager();
        if (!$tables->table_exists('zoom') || !$tables->table_exists('zoom_meeting_details')) {
            mtrace('local_zoomcustom: zoom tables are not present, skipping');
            return;
        }

        $found = occurrence::discover();
        mtrace('local_zoomcustom: recorded ' . $found . ' new recurring occurrence(s)');
    }
}
