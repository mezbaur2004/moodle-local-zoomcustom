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
 * Restore stock mod_zoom before this plugin is removed.
 *
 * Without this, uninstalling the pack would leave the inserted hooks in place
 * with nothing behind them, and upstream would quietly go back to its own
 * grading calculation.
 *
 * @return void
 * @throws moodle_exception when the patch cannot be removed
 */
function local_zoomcustom_pre_uninstall_hook(): void {
    if (!class_exists('\local_patchmanager\api')) {
        throw new moodle_exception('uninstallnoengine', 'local_zoomcustom');
    }

    $status = \local_patchmanager\api::get_status('local_zoomcustom', \local_zoomcustom\patches::ID);
    if ($status === null) {
        \local_zoomcustom\guard::release();
        return;
    }

    if ($status->state !== \local_patchmanager\state::NOT_APPLIED) {
        $result = \local_patchmanager\api::restore($status->definition, false, true);

        $after = \local_patchmanager\api::get_status('local_zoomcustom', \local_zoomcustom\patches::ID);
        if (!$result->success || ($after !== null && $after->state !== \local_patchmanager\state::NOT_APPLIED)) {
            throw new moodle_exception('uninstallblocked', 'local_zoomcustom', '',
                    implode(' ', $result->messages));
        }
    }

    // The guard must not leave the Zoom task paused behind it.
    \local_zoomcustom\guard::release();
}
