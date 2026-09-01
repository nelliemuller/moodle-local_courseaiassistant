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
 * Permission-aware Moodle site and course administration intelligence.
 *
 * This resolver exposes safe administrative structure only when the
 * authenticated Moodle account has the relevant Moodle capabilities.
 *
 * It intentionally does not expose passwords, secrets, API keys,
 * connection credentials, filesystem paths, or arbitrary config values.
 */
final class site_administration_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $coursecontext;

    /** @var \context_system */
    private \context_system $systemcontext;

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;

        $this->coursecontext =
            \context_course::instance(
                (int)$course->id
            );

        $this->systemcontext =
            \context_system::instance();
    }

    /**
     * Resolve safe administration intelligence.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array {
        global $USER, $CFG, $DB;

        $siteadmin =
            is_siteadmin($USER);

        $canconfiguresite =
            has_capability(
                'moodle/site:config',
                $this->systemcontext,
                $USER
            );

        $canupdatecourse =
            has_capability(
                'moodle/course:update',
                $this->coursecontext,
                $USER
            );

        $canmanageactivities =
            has_capability(
                'moodle/course:manageactivities',
                $this->coursecontext,
                $USER
            );

        $canviewparticipants =
            has_capability(
                'moodle/course:viewparticipants',
                $this->coursecontext,
                $USER
            );

        $canviewgrades =
            has_capability(
                'moodle/grade:viewall',
                $this->coursecontext,
                $USER
            );

        $caneditgrades =
            has_capability(
                'moodle/grade:edit',
                $this->coursecontext,
                $USER
            );

        $canviewreports =
            has_capability(
                'report/log:view',
                $this->coursecontext,
                $USER
            ) ||
            has_capability(
                'report/completion:view',
                $this->coursecontext,
                $USER
            );

        $canmanageenrolments =
            has_capability(
                'moodle/course:enrolconfig',
                $this->coursecontext,
                $USER
            );

        $canmanagebadges =
            has_capability(
                'moodle/badges:configurecriteria',
                $this->coursecontext,
                $USER
            ) ||
            has_capability(
                'moodle/badges:manageglobalsettings',
                $this->systemcontext,
                $USER
            );

        $canmanageusers =
            $siteadmin ||
            has_capability(
                'moodle/user:update',
                $this->systemcontext,
                $USER
            );

        $cancreatecourses =
            $siteadmin ||
            has_capability(
                'moodle/course:create',
                \context_coursecat::instance(
                    (int)$this->course->category
                ),
                $USER
            );

        $result = [
            'active' =>
                $siteadmin ||
                $canconfiguresite ||
                $canupdatecourse ||
                $canmanageactivities ||
                $canviewparticipants ||
                $canviewgrades ||
                $caneditgrades ||
                $canviewreports ||
                $canmanageenrolments ||
                $canmanagebadges,

            'authority' => [
                'siteadmin' =>
                    $siteadmin,

                'canconfiguresite' =>
                    $canconfiguresite,

                'canupdatecourse' =>
                    $canupdatecourse,

                'canmanageactivities' =>
                    $canmanageactivities,

                'canviewparticipants' =>
                    $canviewparticipants,

                'canviewgrades' =>
                    $canviewgrades,

                'caneditgrades' =>
                    $caneditgrades,

                'canviewreports' =>
                    $canviewreports,

                'canmanageenrolments' =>
                    $canmanageenrolments,

                'canmanagebadges' =>
                    $canmanagebadges,

                'canmanageusers' =>
                    $canmanageusers,

                'cancreatecourses' =>
                    $cancreatecourses,
            ],

            'course' =>
                $this->course_information(
                    $canupdatecourse
                ),

            'category' =>
                $this->category_information(
                    $canupdatecourse ||
                    $cancreatecourses
                ),

            'enrolment' =>
                $this->enrolment_information(
                    $canmanageenrolments
                ),

            'plugins' =>
                $this->plugin_information(
                    $canconfiguresite
                ),

            'site' =>
                $this->safe_site_information(
                    $canconfiguresite
                ),
        ];

        return $result;
    }

    /**
     * Safe current-course administration information.
     *
     * @param bool $allowed
     * @return array<string, mixed>
     */
    private function course_information(
        bool $allowed
    ): array {
        if (!$allowed) {
            return [
                'available' => false,
            ];
        }

        return [
            'available' => true,

            'id' =>
                (int)$this->course->id,

            'fullname' =>
                format_string(
                    (string)$this->course->fullname,
                    true,
                    [
                        'context' =>
                            $this->coursecontext,
                    ]
                ),

            'shortname' =>
                (string)$this->course->shortname,

            'categoryid' =>
                (int)$this->course->category,

            'visible' =>
                (bool)$this->course->visible,

            'startdate' =>
                (int)$this->course->startdate,

            'enddate' =>
                isset($this->course->enddate)
                    ? (int)$this->course->enddate
                    : 0,

            'format' =>
                (string)$this->course->format,

            'enablecompletion' =>
                !empty($this->course->enablecompletion),

            'groupmode' =>
                isset($this->course->groupmode)
                    ? (int)$this->course->groupmode
                    : 0,

            'groupmodeforce' =>
                !empty($this->course->groupmodeforce),

            'editurl' =>
                (
                    new \moodle_url(
                        '/course/edit.php',
                        [
                            'id' =>
                                (int)$this->course->id,
                        ]
                    )
                )->out(false),
        ];
    }

    /**
     * Current course-category information.
     *
     * @param bool $allowed
     * @return array<string, mixed>
     */
    private function category_information(
        bool $allowed
    ): array {
        if (!$allowed) {
            return [
                'available' => false,
            ];
        }

        try {
            $category =
                \core_course_category::get(
                    (int)$this->course->category,
                    IGNORE_MISSING,
                    true
                );

            if (!$category) {
                return [
                    'available' => false,
                ];
            }

            return [
                'available' => true,

                'id' =>
                    (int)$category->id,

                'name' =>
                    format_string(
                        (string)$category->name,
                        true,
                        [
                            'context' =>
                                \context_coursecat::instance(
                                    (int)$category->id
                                ),
                        ]
                    ),

                'visible' =>
                    (bool)$category->visible,

                'parent' =>
                    (int)$category->parent,

                'coursecount' =>
                    (int)$category->coursecount,
            ];

        } catch (\Throwable $e) {
            return [
                'available' => false,
            ];
        }
    }

    /**
     * Moodle enrolment-method structure for this course.
     *
     * @param bool $allowed
     * @return array<string, mixed>
     */
    private function enrolment_information(
        bool $allowed
    ): array {
        global $DB;

        if (!$allowed) {
            return [
                'available' => false,
            ];
        }

        $instances =
            $DB->get_records(
                'enrol',
                [
                    'courseid' =>
                        (int)$this->course->id,
                ],
                'sortorder ASC, id ASC'
            );

        $result = [];

        foreach ($instances as $instance) {
            $result[] = [
                'id' =>
                    (int)$instance->id,

                'method' =>
                    (string)$instance->enrol,

                'status' =>
                    (int)$instance->status,

                'name' =>
                    isset($instance->name)
                        ? clean_param(
                            (string)$instance->name,
                            PARAM_TEXT
                        )
                        : '',

                'enrolstartdate' =>
                    isset($instance->enrolstartdate)
                        ? (int)$instance->enrolstartdate
                        : 0,

                'enrolenddate' =>
                    isset($instance->enrolenddate)
                        ? (int)$instance->enrolenddate
                        : 0,
            ];
        }

        return [
            'available' => true,
            'instances' => $result,
        ];
    }

    /**
     * Safe installed-plugin inventory.
     *
     * Only site administrators / site configurators receive it.
     *
     * @param bool $allowed
     * @return array<string, mixed>
     */
    private function plugin_information(
        bool $allowed
    ): array {
        if (!$allowed) {
            return [
                'available' => false,
            ];
        }

        $manager =
            \core\plugin_manager::instance();

        $types =
            $manager->get_plugin_types();

        $summary = [];

        foreach ($types as $type => $path) {
            $plugins =
                $manager->get_plugins_of_type(
                    (string)$type
                );

            $summary[(string)$type] = [
                'count' =>
                    count($plugins),

                'plugins' =>
                    array_values(
                        array_map(
                            static function($plugin): array {
                                return [
                                    'component' =>
                                        (string)$plugin->component,

                                    'name' =>
                                        (string)$plugin->displayname,

                                    'versiondisk' =>
                                        isset($plugin->versiondisk)
                                            ? (int)$plugin->versiondisk
                                            : null,

                                    'versiondb' =>
                                        isset($plugin->versiondb)
                                            ? (int)$plugin->versiondb
                                            : null,
                                ];
                            },
                            $plugins
                        )
                    ),
            ];
        }

        return [
            'available' => true,
            'types' => $summary,
        ];
    }

    /**
     * Deliberately small safe site-level snapshot.
     *
     * Do not expose arbitrary get_config() values here.
     *
     * @param bool $allowed
     * @return array<string, mixed>
     */
    private function safe_site_information(
        bool $allowed
    ): array {
        global $CFG, $SITE;

        if (!$allowed) {
            return [
                'available' => false,
            ];
        }

        return [
            'available' => true,

            'fullname' =>
                format_string(
                    (string)$SITE->fullname
                ),

            'shortname' =>
                (string)$SITE->shortname,

            'release' =>
                isset($CFG->release)
                    ? (string)$CFG->release
                    : '',

            'version' =>
                isset($CFG->version)
                    ? (int)$CFG->version
                    : 0,

            'branch' =>
                isset($CFG->branch)
                    ? (string)$CFG->branch
                    : '',

            'theme' =>
                isset($CFG->theme)
                    ? clean_param(
                        (string)$CFG->theme,
                        PARAM_COMPONENT
                    )
                    : '',

            'adminurl' =>
                (
                    new \moodle_url(
                        '/admin/index.php'
                    )
                )->out(false),
        ];
    }
}
