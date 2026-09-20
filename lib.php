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
 * Callbacks for local_zoomcustom.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Register this pack's customisations with local_patchmanager.
 *
 * @return array patch definitions, contract version 1
 */
function local_zoomcustom_patchmanager_patches(): array {
    return \local_zoomcustom\patches::all();
}

/**
 * React to a state change reported by the engine.
 *
 * All Zoom specific safety behaviour lives here, not in the engine.
 *
 * @param array $statuses every known status, keyed by pack:id
 * @return void
 */
function local_zoomcustom_patchmanager_state_changed(array $statuses): void {
    \local_zoomcustom\guard::reset_cache();
    \local_zoomcustom\guard::evaluate(true);
}

/**
 * Block mod_zoom's inline report console while the customisation is not active.
 *
 * Runs after require_login() on every page, so unlike the guard that patch 001
 * inserts into mod_zoom, it still works when the patch itself is missing,
 * outdated or in conflict. The scheduled task being paused does not cover this
 * path, because that script builds the task and runs it inline.
 *
 * @param mixed $courseorid
 * @param mixed $autologinguest
 * @param mixed $cm
 * @param mixed $setwantsurltome
 * @param mixed $preventredirect
 * @return void
 * @throws moodle_exception when Zoom grading must not run
 */
function local_zoomcustom_after_require_login($courseorid = null, $autologinguest = null, $cm = null,
        $setwantsurltome = null, $preventredirect = null): void {
    \local_zoomcustom\guard::protect_current_request();
}

// The uninstall handler lives in db/uninstall.php as xmldb_local_zoomcustom_uninstall().
// Moodle has no *_pre_uninstall_hook() callback, so a function of that name here
// would never be called.
