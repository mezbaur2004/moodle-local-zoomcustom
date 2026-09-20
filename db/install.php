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
 * Install handler for local_zoomcustom.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Evaluate the guard as soon as the plugin exists.
 *
 * On a fresh install the customisation is not applied yet, so upstream grading
 * must already be paused before anyone can trigger it. Waiting for the first
 * scheduled guard_check would leave a window of up to its run interval in which
 * the stock calculation could run and write grades that a later fix cannot
 * lower.
 *
 * Failure here must not abort the install: the plugin is still usable, the
 * scheduled check will catch up, and the status page reports the state.
 *
 * @return bool
 */
function xmldb_local_zoomcustom_install(): bool {
    try {
        \local_zoomcustom\guard::evaluate(true);
    } catch (\Throwable $e) {
        debugging('local_zoomcustom: guard evaluation at install failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    return true;
}
