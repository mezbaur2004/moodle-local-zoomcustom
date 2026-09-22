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
 * Version details for local_zoomcustom.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_zoomcustom';
$plugin->version = 2026092200;
$plugin->requires = 2022112800; // Moodle 4.1.
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '1.1.0 (001-period-grading, 002-recurring-grading phase 1)';
$plugin->dependencies = [
    'local_patchmanager' => 2026092000,
    'mod_zoom' => ANY_VERSION,
];
