<?php

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * AI Course Assistant plugin code.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_courseaiassistant.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_courseaiassistant_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081900) {

        /*
         * Standalone 2.0 introduces its own conversation storage.
         *
         * The earlier local plugin was already installed, so these
         * tables must be created here during upgrade. install.xml is
         * only used for a completely fresh installation.
         */

        $table = new xmldb_table(
            'local_courseaiassistant_conv'
        );

        if (!$dbman->table_exists($table)) {

            $table->add_field(
                'id',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                XMLDB_SEQUENCE,
                null
            );

            $table->add_field(
                'userid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_field(
                'courseid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_field(
                'title',
                XMLDB_TYPE_CHAR,
                '255',
                null,
                XMLDB_NOTNULL,
                null,
                ''
            );

            $table->add_field(
                'timecreated',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_field(
                'timemodified',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_key(
                'primary',
                XMLDB_KEY_PRIMARY,
                ['id']
            );

            $table->add_index(
                'usercourse',
                XMLDB_INDEX_NOTUNIQUE,
                ['userid', 'courseid']
            );

            $dbman->create_table($table);
        }


        $table = new xmldb_table(
            'local_courseaiassistant_msg'
        );

        if (!$dbman->table_exists($table)) {

            $table->add_field(
                'id',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                XMLDB_SEQUENCE,
                null
            );

            $table->add_field(
                'conversationid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_field(
                'role',
                XMLDB_TYPE_CHAR,
                '20',
                null,
                XMLDB_NOTNULL,
                null,
                'user'
            );

            $table->add_field(
                'message',
                XMLDB_TYPE_TEXT,
                null,
                null,
                XMLDB_NOTNULL,
                null,
                null
            );

            $table->add_field(
                'contextjson',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null,
                null,
                null
            );

            $table->add_field(
                'timecreated',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_key(
                'primary',
                XMLDB_KEY_PRIMARY,
                ['id']
            );

            $table->add_key(
                'conversationfk',
                XMLDB_KEY_FOREIGN,
                ['conversationid'],
                'local_courseaiassistant_conv',
                ['id']
            );


            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(
            true,
            2026081900,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082603) {
        unset_config('defaultplacement', 'local_courseaiassistant');

        upgrade_plugin_savepoint(
            true,
            2026082603,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082604) {
        upgrade_plugin_savepoint(
            true,
            2026082604,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082605) {
        upgrade_plugin_savepoint(
            true,
            2026082605,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082606) {
        upgrade_plugin_savepoint(
            true,
            2026082606,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082607) {
        upgrade_plugin_savepoint(
            true,
            2026082607,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082608) {
        upgrade_plugin_savepoint(
            true,
            2026082608,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082609) {
        upgrade_plugin_savepoint(
            true,
            2026082609,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082610) {
        upgrade_plugin_savepoint(
            true,
            2026082610,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082611) {
        upgrade_plugin_savepoint(
            true,
            2026082611,
            'local',
            'courseaiassistant'
        );
    }

    if ($oldversion < 2026082612) {
        upgrade_plugin_savepoint(
            true,
            2026082612,
            'local',
            'courseaiassistant'
        );
    }

    return true;
}
