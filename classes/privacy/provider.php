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


namespace local_courseaiassistant\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for AI Course Assistant.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_courseaiassistant_conv', [
            'userid' => 'privacy:metadata:conversation:userid',
            'courseid' => 'privacy:metadata:conversation:courseid',
            'title' => 'privacy:metadata:conversation:title',
            'timecreated' => 'privacy:metadata:conversation:timecreated',
            'timemodified' => 'privacy:metadata:conversation:timemodified',
        ], 'privacy:metadata:conversation');

        $collection->add_database_table('local_courseaiassistant_msg', [
            'conversationid' => 'privacy:metadata:message:conversationid',
            'role' => 'privacy:metadata:message:role',
            'message' => 'privacy:metadata:message:message',
            'contextjson' => 'privacy:metadata:message:contextjson',
            'timecreated' => 'privacy:metadata:message:timecreated',
        ], 'privacy:metadata:message');

        $collection->add_external_location_link('gemini', [
            'question' => 'privacy:metadata:gemini:question',
            'history' => 'privacy:metadata:gemini:history',
            'coursecontext' => 'privacy:metadata:gemini:coursecontext',
            'rolecontext' => 'privacy:metadata:gemini:rolecontext',
            'progress' => 'privacy:metadata:gemini:progress',
        ], 'privacy:metadata:gemini');

        $collection->add_external_location_link('openai', [
            'question' => 'privacy:metadata:openai:question',
            'history' => 'privacy:metadata:openai:history',
            'coursecontext' => 'privacy:metadata:openai:coursecontext',
            'rolecontext' => 'privacy:metadata:openai:rolecontext',
            'progress' => 'privacy:metadata:openai:progress',
            'safetyidentifier' => 'privacy:metadata:openai:safetyidentifier',
        ], 'privacy:metadata:openai');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_courseaiassistant_conv} conv
                    ON conv.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND conv.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $conversations = $DB->get_records('local_courseaiassistant_conv', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ], 'timecreated ASC');
            $export = [];
            foreach ($conversations as $conversation) {
                $messages = $DB->get_records('local_courseaiassistant_msg',
                    ['conversationid' => $conversation->id], 'id ASC');
                $exportmessages = [];
                foreach ($messages as $message) {
                    $exportmessages[] = (object) [
                        'role' => $message->role,
                        'message' => $message->message,
                        'contextjson' => $message->contextjson,
                        'timecreated' => $message->timecreated,
                    ];
                }
                $export[] = (object) [
                    'title' => $conversation->title,
                    'timecreated' => $conversation->timecreated,
                    'timemodified' => $conversation->timemodified,
                    'messages' => $exportmessages,
                ];
            }
            if ($export) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_courseaiassistant')],
                    (object) ['conversations' => $export]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_course) {
            self::delete_conversations($context->instanceid);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::delete_conversations($context->instanceid, [$userid]);
            }
        }
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        $userlist->add_from_sql('userid',
            'SELECT DISTINCT userid FROM {local_courseaiassistant_conv} WHERE courseid = :courseid',
            ['courseid' => $context->instanceid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_course) {
            self::delete_conversations($context->instanceid, $userlist->get_userids());
        }
    }

    private static function delete_conversations(int $courseid, ?array $userids = null): void {
        global $DB;
        $params = ['courseid' => $courseid];
        $usersql = '';
        if ($userids !== null) {
            if (!$userids) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $usersql = " AND userid {$insql}";
            $params = array_merge($params, $inparams);
        }
        $ids = $DB->get_fieldset_select('local_courseaiassistant_conv', 'id',
            "courseid = :courseid{$usersql}", $params);
        if ($ids) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('local_courseaiassistant_msg', "conversationid {$insql}", $inparams);
        }
        $DB->delete_records_select('local_courseaiassistant_conv', "courseid = :courseid{$usersql}", $params);
    }
}
