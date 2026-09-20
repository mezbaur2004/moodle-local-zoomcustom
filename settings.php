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
 * Settings for local_zoomcustom.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_zoomcustom',
            get_string('pluginname', 'local_zoomcustom'));

    $settings->add(new admin_setting_heading('local_zoomcustom/intro',
            '', get_string('settingsintro', 'local_zoomcustom',
                    (new moodle_url('/local/patchmanager/index.php'))->out())));

    $settings->add(new admin_setting_configcheckbox('local_zoomcustom/guardenabled',
            get_string('guardenabled', 'local_zoomcustom'),
            get_string('guardenabled_desc', 'local_zoomcustom'), 1));

    $settings->add(new admin_setting_configselect('local_zoomcustom/strictness',
            get_string('strictness', 'local_zoomcustom'),
            get_string('strictness_desc', 'local_zoomcustom'),
            \local_zoomcustom\guard::MODE_VERIFIED,
            [
                \local_zoomcustom\guard::MODE_VERIFIED => get_string('strictness_verified', 'local_zoomcustom'),
                \local_zoomcustom\guard::MODE_APPLIED => get_string('strictness_applied', 'local_zoomcustom'),
            ]));

    $ADMIN->add('localplugins', $settings);
}
