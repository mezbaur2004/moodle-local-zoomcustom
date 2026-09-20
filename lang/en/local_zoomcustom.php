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
 * Strings for local_zoomcustom.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Zoom customisations';

// Patch 001.
$string['patch001name'] = 'Period attendance grading';
$string['patch001description'] = 'Grades attendance against the duration configured on the Zoom activity, counting only the attendance that falls inside the activity\'s scheduled window. Upstream instead uses the raw participant duration and the length of the real Zoom occurrence, which can award full marks for partial attendance.';
$string['patch001hunk1'] = 'Replace the denominator and per user attendance in grading_participant_upon_duration()';
$string['patch001hunk2'] = 'Apply the guard to the teacher "Refresh sessions" entry point';

// Guard.
$string['guardok'] = 'The period grading customisation is active and verified.';
$string['guardunverified'] = 'The period grading customisation is applied but has not been verified on the installed mod_zoom version.';
$string['guardstate'] = 'The period grading customisation is not active. Current state: {$a}.';
$string['guardnoengine'] = 'The patch manager is not available, so the state of the customisation cannot be established.';
$string['guardnotregistered'] = 'The period grading customisation is not registered with the patch manager.';
$string['guardacknowledged'] = 'An administrator acknowledged the current condition: {$a}';
$string['guardblocked'] = 'Zoom session reports are paused because a required customisation is not active. {$a}';

// Settings.
$string['settingsintro'] = 'The state of these customisations, and the buttons to apply or restore them, are on the <a href="{$a}">patch manager page</a>. The settings here control what this plugin does when a required customisation is not active.';
$string['guardenabled'] = 'Protect Zoom grading';
$string['guardenabled_desc'] = 'Pause the mod_zoom "Get meeting reports" task while a required customisation is not active. Upstream grading only ever raises a grade, so a single unprotected run can write inflated grades that a later fix cannot lower. Pausing loses no data: the task resumes from its own checkpoint.';
$string['strictness'] = 'Strictness';
$string['strictness_desc'] = 'Which condition counts as safe enough to let Zoom grading run.';
$string['strictness_verified'] = 'Applied and verified on the installed mod_zoom version (recommended)';
$string['strictness_applied'] = 'Applied, even if not yet verified on this version';

// Task.
$string['taskguardcheck'] = 'Check the Zoom customisation guard';

// Dashboard card.
$string['cardtitle'] = 'Zoom customisations';
$string['cardallcurrent'] = 'All customisations are current';
$string['cardattention'] = 'Customisations need attention';
$string['cardneedsreview'] = 'Period grading: {$a}';
$string['cardtaskpaused'] = 'The Zoom report task is paused';
$string['cardacknowledged'] = 'The current condition has been acknowledged by an administrator';
$string['cardheartbeatstale'] = 'The files are patched but the running PHP may still use an older copy';
$string['cardupgradepending'] = 'A mod_zoom upgrade is pending';
$string['cardpatchline'] = '{$a->name}: {$a->state} ({$a->verified})';
$string['cardheartbeat'] = 'Last executed {$a} ago';
$string['cardreview'] = 'Review';

// Uninstall.
$string['uninstallnoengine'] = 'The patch manager is not available, so the customisations in mod_zoom cannot be removed automatically. Restore stock mod_zoom first, then uninstall this plugin.';
$string['uninstallblocked'] = 'The customisations could not be removed from mod_zoom, so the plugin was not uninstalled. Restore them from the patch manager page, or reinstall the stock mod_zoom package. {$a}';

// Privacy.
$string['privacy:metadata'] = 'The Zoom customisations plugin stores no personal data. It changes how mod_zoom calculates the grades it stores, and records the periods during which its customisation was inactive.';
