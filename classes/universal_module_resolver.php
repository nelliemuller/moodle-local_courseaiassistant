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
 * Generic fallback intelligence for any Moodle activity module.
 *
 * This works for core and third-party modules even when there is no
 * specialist adapter.
 *
 * @package local_courseaiassistant
 */
final class universal_module_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var array<string, array<string, mixed>> */
    private array $registry;

    /**
     * @param \stdClass $course
     * @param array<string, array<string, mixed>> $registry
     */
    public function __construct(
        \stdClass $course,
        array $registry
    ) {
        $this->course = $course;
        $this->registry = $registry;
    }

    /**
     * Resolve generic intelligence for all accessible course modules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve_all(): array {
        global $DB, $USER;

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $result = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm instanceof \cm_info) {
                continue;
            }

            $context =
                \context_module::instance(
                    (int)$cm->id
                );

            $canviewhidden =
                has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                );

            if (
                !$cm->uservisible &&
                !$canviewhidden
            ) {
                continue;
            }

            $component =
                'mod_' . $cm->modname;

            $registration =
                $this->registry[$component]
                ?? [
                    'component' =>
                        $component,

                    'modname' =>
                        (string)$cm->modname,

                    'displayname' =>
                        (string)$cm->modname,

                    'source' =>
                        'unknown',

                    'features' =>
                        [],

                    'callbacks' =>
                        [],

                    'specialistadapter' =>
                        'generic',
                ];

            $instance =
                $this->instance_record(
                    (string)$cm->modname,
                    (int)$cm->instance
                );

            $result[] = [
                'cmid' =>
                    (int)$cm->id,

                'instanceid' =>
                    (int)$cm->instance,

                'component' =>
                    $component,

                'modname' =>
                    (string)$cm->modname,

                'name' =>
                    format_string(
                        (string)$cm->name,
                        true,
                        ['context' => $context]
                    ),

                'url' =>
                    $cm->url
                        ? $cm->url->out(false)
                        : '',

                'plugin' =>
                    $registration,

                'instancefields' =>
                    $this->safe_instance_fields(
                        $instance
                    ),

                'capabilities' =>
                    $this->context_capabilities(
                        $context
                    ),
            ];
        }

        return $result;
    }

    /**
     * Read the plugin instance table generically.
     *
     * @param string $table
     * @param int $instanceid
     * @return \stdClass|null
     */
    private function instance_record(
        string $table,
        int $instanceid
    ): ?\stdClass {
        global $DB;

        if (
            $table === '' ||
            $instanceid <= 0
        ) {
            return null;
        }

        try {
            $manager =
                $DB->get_manager();

            $xmldbtable =
                new \xmldb_table(
                    $table
                );

            if (
                !$manager->table_exists(
                    $xmldbtable
                )
            ) {
                return null;
            }

            $record =
                $DB->get_record(
                    $table,
                    ['id' => $instanceid],
                    '*',
                    IGNORE_MISSING
                );

            return $record ?: null;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Generic safe view of an activity instance record.
     *
     * @param \stdClass|null $record
     * @return array<string, mixed>
     */
    private function safe_instance_fields(
        ?\stdClass $record
    ): array {
        if (!$record) {
            return [];
        }

        $result = [];

        foreach ((array)$record as $field => $value) {
            $normalized =
                strtolower(
                    preg_replace(
                        '/[^a-z0-9]/i',
                        '',
                        (string)$field
                    )
                );

            if (
                preg_match(
                    '/password|passwd|secret|token|apikey|privatekey|clientsecret|consumersecret/',
                    $normalized
                )
            ) {
                continue;
            }

            if (
                is_string($value) &&
                strlen($value) > 4000
            ) {
                $value =
                    substr(
                        $value,
                        0,
                        4000
                    );
            }

            if (
                is_scalar($value) ||
                $value === null
            ) {
                $result[(string)$field] =
                    $value;
            }
        }

        return $result;
    }

    /**
     * Important contextual capabilities.
     *
     * Specialist adapters can add more module-specific capabilities.
     *
     * @param \context_module $context
     * @return array<string, bool>
     */
    private function context_capabilities(
        \context_module $context
    ): array {
        return [
            'manageactivities' =>
                has_capability(
                    'moodle/course:manageactivities',
                    $context
                ),

            'viewhidden' =>
                has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                ),

            'accessallgroups' =>
                has_capability(
                    'moodle/site:accessallgroups',
                    $context
                ),
        ];
    }
}
