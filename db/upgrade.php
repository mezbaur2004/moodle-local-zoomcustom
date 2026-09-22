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
 * Upgrade steps for local_zoomcustom.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Apply the upgrade steps for a given starting version.
 *
 * @param int $oldversion the version the site is upgrading from
 * @return bool
 */
function xmldb_local_zoomcustom_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092200) {
        $table = new xmldb_table('local_zoomcustom_occurrence');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('zoomid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('detailsid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('participantsig', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('settledtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('finalizedtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reviewedtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reviewnote', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('zoomid', XMLDB_KEY_FOREIGN, ['zoomid'], 'zoom', ['id']);
        $table->add_key('detailsid', XMLDB_KEY_FOREIGN_UNIQUE, ['detailsid'], 'zoom_meeting_details', ['id']);
        $table->add_key('reviewedby', XMLDB_KEY_FOREIGN, ['reviewedby'], 'user', ['id']);

        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092200, 'local', 'zoomcustom');
    }

    if ($oldversion < 2026092202) {
        $table = new xmldb_table('local_zoomcustom_attendance');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('occurrenceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('countedseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('occurrenceid', XMLDB_KEY_FOREIGN, ['occurrenceid'], 'local_zoomcustom_occurrence', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('occurrenceid-userid', XMLDB_INDEX_UNIQUE, ['occurrenceid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092202, 'local', 'zoomcustom');
    }

    return true;
}
