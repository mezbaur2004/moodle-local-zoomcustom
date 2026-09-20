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

use local_zoomcustom\guard;

/**
 * Re-evaluates the Zoom guard on its own schedule.
 *
 * The engine also calls the guard whenever state changes, but this task means
 * the protection keeps working even if the engine's own task is disabled.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard_check extends \core\task\scheduled_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskguardcheck', 'local_zoomcustom');
    }

    /**
     * Run the guard.
     *
     * @return void
     */
    public function execute(): void {
        guard::reset_cache();
        $result = guard::evaluate(true);

        mtrace('local_zoomcustom guard: ' . ($result->safe ? 'safe' : 'NOT SAFE')
                . ' - ' . $result->reason);

        if ($result->taskpaused) {
            mtrace('local_zoomcustom: the Zoom report task is paused'
                    . ($result->pausedbyus ? ' by this plugin.' : ' by an administrator.'));
        }
    }
}
