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
 * Permission-aware read-only resolver for the actual content and
 * configuration stored inside Moodle activities and resources.
 *
 * This supplements existing course, grading, forum, completion,
 * availability, and resource intelligence.
 *
 * @package local_courseaiassistant
 */
final class activity_content_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $coursecontext;

    /** @var array<string, bool> */
    private array $sensitivefields = [];

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext =
            \context_course::instance((int)$course->id);

        $this->sensitivefields = [
            'password' => true,
            'passwd' => true,
            'secret' => true,
            'token' => true,
            'accesstoken' => true,
            'refreshtoken' => true,
            'privatekey' => true,
            'apikey' => true,
            'api_key' => true,
            'consumersecret' => true,
            'clientsecret' => true,
            'oauthsecret' => true,
        ];
    }

    /**
     * Resolve the content of every Moodle course module the current
     * authenticated user is permitted to inspect.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve_all(): array {
        global $USER;

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
                \context_module::instance((int)$cm->id);

            $canviewhidden =
                has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                );

            if (!$cm->uservisible && !$canviewhidden) {
                continue;
            }

            $result[] =
                $this->resolve_cm(
                    $cm,
                    $context
                );
        }

        return $result;
    }

    /**
     * Resolve one Moodle activity/resource.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function resolve_cm(
        \cm_info $cm,
        \context_module $context
    ): array {
        $instance =
            $this->instance_record($cm);

        return [
            'cmid' =>
                (int)$cm->id,

            'instanceid' =>
                (int)$cm->instance,

            'module' =>
                (string)$cm->modname,

            'component' =>
                'mod_' . (string)$cm->modname,

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

            /*
             * Moodle module instance settings.
             *
             * This is deliberately sanitized before being exposed
             * to the reasoning layer.
             */
            'settings' =>
                $this->sanitize_record(
                    $instance,
                    $context
                ),

            /*
             * Common human-authored content fields.
             */
            'content' =>
                $this->content_fields(
                    $instance,
                    $context
                ),

            /*
             * Permission-aware browsable file/resource structure.
             */
            'files' =>
                $this->browse_module_files(
                    $context
                ),

            'permissions' => [
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

                'updatecourse' =>
                    has_capability(
                        'moodle/course:update',
                        $this->coursecontext
                    ),
            ],
        ];
    }

    /**
     * Fetch the standard module instance record.
     *
     * Moodle activity modules have an instance table matching the
     * activity plugin name. Third-party modules which do not expose
     * a standard table are handled safely by returning null.
     *
     * @param \cm_info $cm
     * @return \stdClass|null
     */
    private function instance_record(
        \cm_info $cm
    ): ?\stdClass {
        global $DB;

        $table =
            (string)$cm->modname;

        if ($table === '') {
            return null;
        }

        try {
            $manager =
                $DB->get_manager();

            $xmldbtable =
                new \xmldb_table($table);

            if (!$manager->table_exists($xmldbtable)) {
                return null;
            }

            $record =
                $DB->get_record(
                    $table,
                    ['id' => (int)$cm->instance],
                    '*',
                    IGNORE_MISSING
                );

            return $record ?: null;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract common human-authored text fields without assuming
     * that every module has the same schema.
     *
     * @param \stdClass|null $record
     * @param \context_module $context
     * @return array<string, string>
     */
    private function content_fields(
        ?\stdClass $record,
        \context_module $context
    ): array {
        if (!$record) {
            return [];
        }

        $fields = [
            'intro',
            'content',
            'description',
            'instructions',
            'summary',
            'activity',
        ];

        $out = [];

        foreach ($fields as $field) {
            if (
                !property_exists($record, $field) ||
                !is_string($record->{$field}) ||
                trim($record->{$field}) === ''
            ) {
                continue;
            }

            $text =
                format_text(
                    $record->{$field},
                    FORMAT_HTML,
                    [
                        'context' => $context,
                        'filter' => true,
                        'noclean' => false,
                    ]
                );

            $plain =
                trim(
                    preg_replace(
                        '/\s+/u',
                        ' ',
                        html_entity_decode(
                            strip_tags($text),
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        )
                    )
                );

            if ($plain !== '') {
                $out[$field] = $plain;
            }
        }

        return $out;
    }

    /**
     * Safely expose module settings while removing credentials and
     * large/internal values which should not enter an AI request.
     *
     * @param \stdClass|null $record
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function sanitize_record(
        ?\stdClass $record,
        \context_module $context
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

            if ($this->is_sensitive_field($normalized)) {
                continue;
            }

            /*
             * Exclude large text already represented through
             * content_fields().
             */
            if (
                in_array(
                    (string)$field,
                    [
                        'intro',
                        'content',
                        'description',
                        'instructions',
                        'summary',
                    ],
                    true
                )
            ) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $stringvalue =
                    is_string($value)
                        ? trim($value)
                        : $value;

                /*
                 * Avoid unexpectedly dumping huge opaque settings.
                 */
                if (
                    is_string($stringvalue) &&
                    strlen($stringvalue) > 4000
                ) {
                    $stringvalue =
                        substr($stringvalue, 0, 4000);
                }

                $result[(string)$field] =
                    $stringvalue;
            }
        }

        return $result;
    }

    /**
     * Determine whether a setting field looks credential-sensitive.
     *
     * @param string $normalized
     * @return bool
     */
    private function is_sensitive_field(
        string $normalized
    ): bool {
        if (isset($this->sensitivefields[$normalized])) {
            return true;
        }

        foreach (
            [
                'password',
                'secret',
                'token',
                'privatekey',
                'apikey',
                'consumersecret',
                'clientsecret',
            ] as $needle
        ) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Browse files using Moodle's permission-aware File Browser API.
     *
     * This intentionally returns metadata and structure only.
     * Actual file contents should be loaded only when relevant to a
     * user request.
     *
     * @param \context_module $context
     * @return array<int, array<string, mixed>>
     */
    private function browse_module_files(
        \context_module $context
    ): array {
        try {
            $browser =
                get_file_browser();

            $root =
                $browser->get_file_info(
                    $context
                );

            if (!$root) {
                return [];
            }

            $items = [];

            $this->walk_file_tree(
                $root,
                $items,
                0
            );

            return $items;

        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Recursively walk Moodle's permission-aware file tree.
     *
     * Hard limits prevent one large repository from flooding the
     * reasoning context.
     *
     * @param \file_info $node
     * @param array<int, array<string, mixed>> $items
     * @param int $depth
     * @return void
     */
    private function walk_file_tree(
        \file_info $node,
        array &$items,
        int $depth
    ): void {
        if ($depth > 8 || count($items) >= 250) {
            return;
        }

        $children =
            $node->get_children();

        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            if (!$child instanceof \file_info) {
                continue;
            }

            if (count($items) >= 250) {
                return;
            }

            $mimetype =
                method_exists($child, 'get_mimetype')
                    ? $child->get_mimetype()
                    : null;

            $filesize =
                method_exists($child, 'get_filesize')
                    ? $child->get_filesize()
                    : null;

            $url = '';

            try {
                $childurl =
                    $child->get_url();

                if ($childurl instanceof \moodle_url) {
                    $url =
                        $childurl->out(false);
                } elseif (is_string($childurl)) {
                    $url = $childurl;
                }
            } catch (\Throwable $e) {
                $url = '';
            }

            $items[] = [
                'name' =>
                    (string)$child->get_visible_name(),

                'mimetype' =>
                    $mimetype
                        ? (string)$mimetype
                        : '',

                'filesize' =>
                    is_numeric($filesize)
                        ? (int)$filesize
                        : null,

                'url' =>
                    $url,

                'depth' =>
                    $depth,
            ];

            $this->walk_file_tree(
                $child,
                $items,
                $depth + 1
            );
        }
    }
}
