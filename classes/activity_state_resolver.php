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
 * Universal read-only Moodle activity and resource state resolver.
 *
 * This resolver describes what Moodle itself knows about every
 * course module for the currently authenticated user.
 *
 * It does not infer teacher work.
 * It does not change Moodle.
 * It does not replace existing forum, grading, or completion engines.
 *
 * @package local_courseaiassistant
 */
final class activity_state_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $coursecontext;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext =
            \context_course::instance((int)$course->id);
    }

    /**
     * Resolve compact Moodle-native state for all course modules.
     *
     * Permission checks are always made for the authenticated user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve_all(): array {
        global $USER;

        $modinfo = get_fast_modinfo(
            $this->course,
            $USER->id
        );

        $states = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm instanceof \cm_info) {
                continue;
            }

            $modulecontext =
                \context_module::instance((int)$cm->id);

            /*
             * Do not expose a completely hidden activity to a user
             * who cannot view hidden activities.
             */
            $canviewhidden = has_capability(
                'moodle/course:viewhiddenactivities',
                $modulecontext
            );

            if (!$cm->uservisible && !$canviewhidden) {
                continue;
            }

            $states[] =
                $this->resolve_cm(
                    $cm,
                    $modulecontext,
                    $modinfo
                );
        }

        return $states;
    }

    /**
     * Resolve one course module.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function resolve_cm(
        \cm_info $cm,
        \context_module $context,
        \course_modinfo $modinfo
    ): array {
        $availableinfo = $cm->availableinfo ?? '';

        if (is_object($availableinfo)) {
            if (method_exists($availableinfo, 'get_message')) {
                $availableinfo = $availableinfo->get_message();
            } else if (method_exists($availableinfo, 'get_messages')) {
                $messages = $availableinfo->get_messages();
                $availableinfo = is_array($messages)
                    ? implode(' ', $messages)
                    : '';
            } else {
                $availableinfo = '';
            }
        }

        if (is_array($availableinfo)) {
            $availableinfo = implode(
                ' ',
                array_map(
                    static fn($item): string =>
                        is_scalar($item) ? (string)$item : '',
                    $availableinfo
                )
            );
        }

        $availabilitytext =
            trim(
                strip_tags(
                    html_entity_decode(
                        (string)$availableinfo,
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                )
            );

        $state = [
            'cmid' =>
                (int)$cm->id,

            'instanceid' =>
                (int)$cm->instance,

            'module' =>
                (string)$cm->modname,

            'name' =>
                format_string(
                    (string)$cm->name,
                    true,
                    ['context' => $context]
                ),

            'sectionnum' =>
                (int)$cm->sectionnum,

            'url' =>
                $cm->url
                    ? $cm->url->out(false)
                    : '',

            /*
             * Visibility and access are not the same thing.
             */
            'visible' =>
                (bool)$cm->visible,

            'uservisible' =>
                (bool)$cm->uservisible,

            'available' =>
                empty($cm->availableinfo),

            'availabilityexplanation' =>
                $availabilitytext,

            /*
             * Raw configured availability JSON is valuable to the
             * reasoning layer because it preserves nested AND/OR
             * conditions instead of flattening them into prose.
             */
            'availabilityconfiguration' =>
                $this->availability_configuration($cm),

            /*
             * Editing teachers, managers and administrators may need
             * to understand every configured restriction, including
             * conditions which are already satisfied.
             *
             * Participants receive only the Moodle information that
             * applies to their own access state.
             */
            'availabilitydetails' =>
                $this->availability_details(
                    $cm,
                    $context,
                    $modinfo
                ),

            'dates' => [
                'completionexpected' =>
                    (int)($cm->completionexpected ?? 0),
            ],

            'completion' =>
                $this->completion_configuration($cm),

            'groups' => [
                'groupmode' =>
                    (int)$cm->groupmode,

                'groupingid' =>
                    (int)$cm->groupingid,

                'canaccessallgroups' =>
                    has_capability(
                        'moodle/site:accessallgroups',
                        $context
                    ),
            ],

            'permissions' =>
                $this->permissions($context),

            /*
             * Keep Moodle module-specific custom state available.
             * This can contain completion rules supplied by the
             * activity plugin itself.
             */
            'customdata' =>
                $this->safe_customdata($cm),
        ];

        return $state;
    }

    /**
     * Preserve the configured Moodle availability expression.
     *
     * Moodle stores nested availability conditions as JSON.
     * Keeping the decoded structure allows the reasoning layer to
     * distinguish AND/OR groups and individual condition types.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>|null
     */
    private function availability_configuration(
        \cm_info $cm
    ): ?array {
        $raw =
            $this->safe_property(
                $cm,
                'availability'
            );

        if (is_array($raw)) {
            return $raw;
        }

        if (is_object($raw)) {
            return (array)$raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded =
            json_decode(
                $raw,
                true
            );

        return is_array($decoded)
            ? $decoded
            : null;
    }

    /**
     * Return permission-aware availability information.
     *
     * For participants, use only Moodle's user-facing explanation.
     *
     * For users who can manage activities or view hidden activities,
     * also expose Moodle's full configured restriction description.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param \course_modinfo $modinfo
     * @return array<string, mixed>
     */
    private function availability_details(
        \cm_info $cm,
        \context_module $context,
        \course_modinfo $modinfo
    ): array {
        $availableinfo = $cm->availableinfo ?? '';

        if (is_object($availableinfo)) {
            if (method_exists($availableinfo, 'get_message')) {
                $availableinfo = $availableinfo->get_message();
            } else if (method_exists($availableinfo, 'get_messages')) {
                $messages = $availableinfo->get_messages();
                $availableinfo = is_array($messages)
                    ? implode(
                        ' ',
                        array_map(
                            static fn($item): string =>
                                is_scalar($item) ? (string)$item : '',
                            $messages
                        )
                    )
                    : '';
            } else {
                $availableinfo = '';
            }
        }

        if (is_array($availableinfo)) {
            $availableinfo = implode(
                ' ',
                array_map(
                    static fn($item): string =>
                        is_scalar($item) ? (string)$item : '',
                    $availableinfo
                )
            );
        }

        if (!is_scalar($availableinfo) && $availableinfo !== null) {
            $availableinfo = '';
        }

        $details = [
            'currentlyaccessible' =>
                (bool)$cm->uservisible,

            'currentuserexplanation' =>
                trim(
                    strip_tags(
                        html_entity_decode(
                            (string)$availableinfo,
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        )
                    )
                ),

            'fullconfigurationexplanation' =>
                '',
        ];

        $caninspectconfiguration =
            has_capability(
                'moodle/course:manageactivities',
                $context
            ) ||
            has_capability(
                'moodle/course:viewhiddenactivities',
                $context
            );

        if (!$caninspectconfiguration) {
            return $details;
        }

        try {
            $info =
                new \core_availability\info_module(
                    $cm
                );

            $full =
                $info->get_full_information(
                    $modinfo
                );

            $details['fullconfigurationexplanation'] =
                trim(
                    strip_tags(
                        html_entity_decode(
                            (string)$full,
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        )
                    )
                );

            $details['availableforall'] =
                $info->is_available_for_all();

        } catch (\Throwable $e) {
            /*
             * Availability intelligence must never break course
             * rendering merely because one third-party condition
             * plugin contains unusual data.
             */
            $details['inspectionavailable'] = false;

            return $details;
        }

        $details['inspectionavailable'] = true;

        return $details;
    }

    /**
     * Moodle completion configuration for an activity/resource.
     *
     * This records configuration, not an inferred completion result.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>
     */
    private function completion_configuration(
        \cm_info $cm
    ): array {
        $completion = [
            'tracking' =>
                (int)$cm->completion,

            'viewrequired' =>
                !empty($cm->completionview),

            'expectedby' =>
                (int)($cm->completionexpected ?? 0),

            'customrules' => [],
        ];

        /*
         * Moodle activity modules can expose custom completion
         * conditions through cm_info custom data.
         */
        $customdata =
            $this->safe_customdata($cm);

        if (
            isset($customdata['customcompletionrules']) &&
            is_array(
                $customdata['customcompletionrules']
            )
        ) {
            $completion['customrules'] =
                $customdata['customcompletionrules'];
        }

        /*
         * Where an activity module provides Moodle's standard
         * human-readable callback, preserve those descriptions too.
         */
        $component =
            'mod_' . $cm->modname;

        if (
            function_exists('component_callback') &&
            component_callback(
                $component,
                'get_completion_active_rule_descriptions',
                [$cm],
                null
            ) !== null
        ) {
            $descriptions =
                component_callback(
                    $component,
                    'get_completion_active_rule_descriptions',
                    [$cm],
                    []
                );

            if (is_array($descriptions)) {
                $completion['ruledescriptions'] =
                    array_values(
                        array_filter(
                            array_map(
                                static function($value): string {
                                    return trim(
                                        strip_tags(
                                            (string)$value
                                        )
                                    );
                                },
                                $descriptions
                            )
                        )
                    );
            }
        }

        return $completion;
    }

    /**
     * Capabilities that affect how the assistant may interpret
     * this activity for the current authenticated user.
     *
     * @param \context_module $context
     * @return array<string, bool>
     */
    private function permissions(
        \context_module $context
    ): array {
        return [
            'viewhidden' =>
                has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                ),

            'manageactivities' =>
                has_capability(
                    'moodle/course:manageactivities',
                    $context
                ),

            'updatecourse' =>
                has_capability(
                    'moodle/course:update',
                    $this->coursecontext
                ),

            'viewparticipants' =>
                has_capability(
                    'moodle/course:viewparticipants',
                    $this->coursecontext
                ),

            'gradeall' =>
                has_capability(
                    'moodle/grade:viewall',
                    $this->coursecontext
                ),

            'editgrades' =>
                has_capability(
                    'moodle/grade:edit',
                    $this->coursecontext
                ),
        ];
    }

    /**
     * Return cm_info customdata as an ordinary array where possible.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>
     */
    private function safe_customdata(
        \cm_info $cm
    ): array {
        $data =
            $this->safe_property(
                $cm,
                'customdata'
            );

        if (is_array($data)) {
            return $data;
        }

        if (is_object($data)) {
            return (array)$data;
        }

        return [];
    }

    /**
     * Safely retrieve a cm_info property without assuming that
     * every Moodle module exposes every field.
     *
     * @param \cm_info $cm
     * @param string $property
     * @return mixed
     */
    private function safe_property(
        \cm_info $cm,
        string $property
    ) {
        try {
            return $cm->{$property} ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
