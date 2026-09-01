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

namespace local_courseaiassistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Permission-aware introspection of installed Moodle activity modules.
 *
 * This resolver does not execute arbitrary plugin callbacks.
 * It discovers safe Moodle-facing information about installed modules
 * and their course instances so unknown third-party activities are not
 * treated as opaque objects.
 *
 * @package local_courseaiassistant
 */
final class module_introspection_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $coursecontext;

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;

        $this->coursecontext =
            \context_course::instance(
                (int)$course->id
            );
    }

    /**
     * Resolve installed module intelligence.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array {
        global $CFG, $DB, $USER;

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $instances = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm instanceof \cm_info) {
                continue;
            }

            $context =
                \context_module::instance(
                    (int)$cm->id
                );

            /*
             * Respect Moodle visibility.
             */
            if (
                !$cm->uservisible &&
                !has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                )
            ) {
                continue;
            }

            $modname =
                clean_param(
                    (string)$cm->modname,
                    PARAM_PLUGIN
                );

            if ($modname === '') {
                continue;
            }

            $record = null;

            /*
             * Activity modules conventionally store their instance
             * record in the table named after the module.
             *
             * We first verify that the table exists.
             */
            try {
                $dbman =
                    $DB->get_manager();

                $table =
                    new \xmldb_table(
                        $modname
                    );

                if ($dbman->table_exists($table)) {
                    $record =
                        $DB->get_record(
                            $modname,
                            [
                                'id' =>
                                    (int)$cm->instance,
                            ],
                            '*',
                            IGNORE_MISSING
                        );
                }
            } catch (\Throwable $e) {
                $record = null;
            }

            $instances[] = [
                'cmid' =>
                    (int)$cm->id,

                'instanceid' =>
                    (int)$cm->instance,

                'modname' =>
                    $modname,

                'component' =>
                    'mod_' . $modname,

                'name' =>
                    format_string(
                        (string)$cm->name,
                        true,
                        [
                            'context' =>
                                $context,
                        ]
                    ),

                'url' =>
                    $cm->url
                        ? $cm->url->out(false)
                        : '',

                'visible' =>
                    (bool)$cm->visible,

                'uservisible' =>
                    (bool)$cm->uservisible,

                'plugin' =>
                    $this->plugin_information(
                        $modname
                    ),

                'callbacks' =>
                    $this->discover_callbacks(
                        $modname
                    ),

                'capabilities' =>
                    $this->module_capabilities(
                        $context,
                        $modname
                    ),

                /*
                 * Safe configuration snapshot.
                 * Sensitive-looking fields are excluded.
                 */
                'instanceconfiguration' =>
                    $record
                        ? $this->sanitize_record(
                            $record
                        )
                        : [],
            ];
        }

        return [
            'active' => true,
            'instances' => $instances,
        ];
    }

    /**
     * Installed plugin information.
     *
     * @param string $modname
     * @return array<string, mixed>
     */
    private function plugin_information(
        string $modname
    ): array {
        $manager =
            \core\plugin_manager::instance();

        $plugin =
            $manager->get_plugin_info(
                'mod_' . $modname
            );

        if (!$plugin) {
            return [];
        }

        $result = [
            'component' =>
                (string)$plugin->component,

            'versiondisk' =>
                isset($plugin->versiondisk)
                    ? (int)$plugin->versiondisk
                    : null,

            'versiondb' =>
                isset($plugin->versiondb)
                    ? (int)$plugin->versiondb
                    : null,
        ];

        try {
            $result['displayname'] =
                (string)$plugin->displayname;
        } catch (\Throwable $e) {
            $result['displayname'] =
                $modname;
        }

        return $result;
    }

    /**
     * Discover known Moodle-facing callbacks without invoking them.
     *
     * @param string $modname
     * @return array<string, bool>
     */
    private function discover_callbacks(
        string $modname
    ): array {
        global $CFG;

        $lib =
            $CFG->dirroot .
            '/mod/' .
            $modname .
            '/lib.php';

        if (!is_readable($lib)) {
            return [];
        }

        /*
         * Loading lib.php is normal Moodle plugin architecture.
         * We only inspect whether conventional callbacks exist.
         */
        require_once($lib);

        $callbacks = [
            'supports' =>
                $modname . '_supports',

            'get_coursemodule_info' =>
                $modname . '_get_coursemodule_info',

            'get_completion_state' =>
                $modname . '_get_completion_state',

            'get_user_grades' =>
                $modname . '_get_user_grades',

            'update_grades' =>
                $modname . '_update_grades',

            'grade_item_update' =>
                $modname . '_grade_item_update',

            'user_outline' =>
                $modname . '_user_outline',

            'user_complete' =>
                $modname . '_user_complete',

            'get_recent_mod_activity' =>
                $modname . '_get_recent_mod_activity',

            'print_recent_mod_activity' =>
                $modname . '_print_recent_mod_activity',

            'extend_settings_navigation' =>
                $modname . '_extend_settings_navigation',

            'extend_navigation' =>
                $modname . '_extend_navigation',

            'pluginfile' =>
                $modname . '_pluginfile',
        ];

        $result = [];

        foreach (
            $callbacks as $label => $function
        ) {
            $result[$label] =
                function_exists(
                    $function
                );
        }

        return $result;
    }

    /**
     * Permission snapshot for the authenticated user.
     *
     * Only capabilities belonging to this activity component
     * are included.
     *
     * @param \context_module $context
     * @param string $modname
     * @return array<string, bool>
     */
    private function module_capabilities(
        \context_module $context,
        string $modname
    ): array {
        $component =
            'mod/' . $modname . ':';

        $all =
            get_all_capabilities();

        $result = [];

        foreach ($all as $capability) {
            if (
                !is_object($capability) ||
                empty($capability->name)
            ) {
                continue;
            }

            $name =
                (string)$capability->name;

            if (
                strpos(
                    $name,
                    $component
                ) !== 0
            ) {
                continue;
            }

            $result[$name] =
                has_capability(
                    $name,
                    $context
                );
        }

        ksort($result);

        return $result;
    }

    /**
     * Safe activity configuration.
     *
     * @param \stdClass $record
     * @return array<string, mixed>
     */
    private function sanitize_record(
        \stdClass $record
    ): array {
        $result = [];

        foreach (
            get_object_vars(
                $record
            ) as $key => $value
        ) {
            $lower =
                strtolower(
                    (string)$key
                );

            /*
             * Never expose credential-like configuration.
             */
            if (
                preg_match(
                    '/password|passwd|secret|token|apikey|api_key|' .
                    'accesskey|access_key|privatekey|private_key|' .
                    'credential|authkey|consumerkey|clientsecret/',
                    $lower
                )
            ) {
                continue;
            }

            if (
                is_scalar($value) ||
                $value === null
            ) {
                if (
                    is_string($value) &&
                    strlen($value) > 20000
                ) {
                    $value =
                        substr(
                            $value,
                            0,
                            20000
                        );
                }

                $result[$key] =
                    $value;
            }
        }

        return $result;
    }
}
