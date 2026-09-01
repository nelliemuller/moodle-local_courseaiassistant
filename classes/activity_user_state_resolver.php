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
 * Universal read-only Moodle activity state for the authenticated user.
 *
 * This resolves actual completion and grade evidence rather than
 * configuration alone.
 *
 * It works for core and third-party activity modules through
 * Moodle's own completion and grade APIs.
 *
 * @package local_courseaiassistant
 */
final class activity_user_state_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \completion_info */
    private \completion_info $completion;

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        global $CFG;

        require_once(
            $CFG->libdir . '/completionlib.php'
        );

        require_once(
            $CFG->libdir . '/gradelib.php'
        );

        $this->course = $course;

        $this->completion =
            new \completion_info(
                $course
            );
    }

    /**
     * Resolve actual state for the authenticated user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve_current_user(): array {
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

            $result[] = [
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

                'userid' =>
                    (int)$USER->id,

                'completion' =>
                    $this->completion_state(
                        $cm,
                        (int)$USER->id
                    ),

                'grades' =>
                    $this->grade_state(
                        $cm,
                        (int)$USER->id
                    ),

                'access' => [
                    'uservisible' =>
                        (bool)$cm->uservisible,

                    'available' =>
                        empty($cm->availableinfo),

                    'availabilityexplanation' =>
                        is_scalar($cm->availableinfo ?? '')
                            ? trim(
                                strip_tags(
                                    html_entity_decode(
                                        (string)($cm->availableinfo ?? ''),
                                        ENT_QUOTES | ENT_HTML5,
                                        'UTF-8'
                                    )
                                )
                            )
                            : '',
                ],

                'url' =>
                    $cm->url
                        ? $cm->url->out(false)
                        : '',
            ];
        }

        return $result;
    }

    /**
     * Actual Moodle completion state for one user/activity.
     *
     * Moodle may include core completion status, required-grade
     * completion, and custom module completion rule statuses.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array<string, mixed>
     */
    private function completion_state(
        \cm_info $cm,
        int $userid
    ): array {
        if (
            !$this->completion->is_enabled(
                $cm
            )
        ) {
            return [
                'enabled' =>
                    false,

                'state' =>
                    null,

                'complete' =>
                    false,

                'details' =>
                    [],
            ];
        }

        try {
            $data =
                $this->completion->get_data(
                    $cm,
                    false,
                    $userid
                );

            $state =
                isset($data->completionstate)
                    ? (int)$data->completionstate
                    : (
                        isset($data->completionstate)
                            ? (int)$data->completionstate
                            : null
                    );

            /*
             * Different Moodle versions and activity plugins may
             * expose additional completion fields on this object.
             * Preserve scalar evidence rather than assuming only
             * one fixed schema.
             */
            $details = [];

            foreach ((array)$data as $key => $value) {
                if (
                    is_scalar($value) ||
                    $value === null
                ) {
                    $details[(string)$key] =
                        $value;
                } elseif (is_object($value)) {
                    $scalars = [];

                    foreach ((array)$value as $subkey => $subvalue) {
                        if (
                            is_scalar($subvalue) ||
                            $subvalue === null
                        ) {
                            $scalars[(string)$subkey] =
                                $subvalue;
                        }
                    }

                    if ($scalars) {
                        $details[(string)$key] =
                            $scalars;
                    }
                }
            }

            return [
                'enabled' =>
                    true,

                'state' =>
                    $state,

                'complete' =>
                    in_array(
                        $state,
                        [
                            COMPLETION_COMPLETE,
                            COMPLETION_COMPLETE_PASS,
                            COMPLETION_COMPLETE_FAIL,
                        ],
                        true
                    ),

                'passed' =>
                    $state === COMPLETION_COMPLETE_PASS,

                'failed' =>
                    $state === COMPLETION_COMPLETE_FAIL,

                'details' =>
                    $details,
            ];

        } catch (\Throwable $e) {
            return [
                'enabled' =>
                    true,

                'state' =>
                    null,

                'complete' =>
                    false,

                'details' =>
                    [],

                'inspectionavailable' =>
                    false,
            ];
        }
    }

    /**
     * Existing grade evidence for one user/activity.
     *
     * This intentionally uses grade_item::get_grade(..., false)
     * so a read operation never creates an empty grade record.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return array<int, array<string, mixed>>
     */
    private function grade_state(
        \cm_info $cm,
        int $userid
    ): array {
        try {
            $items =
                grade_get_grade_items_for_activity(
                    $cm,
                    false
                );

            if (!$items) {
                return [];
            }

            if (
                $items instanceof \grade_item
            ) {
                $items = [$items];
            }

            if (!is_array($items)) {
                return [];
            }

            $result = [];

            foreach ($items as $item) {
                if (
                    !$item instanceof \grade_item
                ) {
                    continue;
                }

                $grade =
                    $item->get_grade(
                        $userid,
                        false
                    );

                $result[] = [
                    'gradeitemid' =>
                        (int)$item->id,

                    'itemnumber' =>
                        (int)$item->itemnumber,

                    'name' =>
                        (string)$item->get_name(),

                    'gradetype' =>
                        (int)$item->gradetype,

                    'grademin' =>
                        (float)$item->grademin,

                    'grademax' =>
                        (float)$item->grademax,

                    'gradepass' =>
                        (float)$item->gradepass,

                    'hidden' =>
                        (bool)$item->get_hidden(),

                    'locked' =>
                        (bool)$item->is_locked(),

                    /*
                     * A grade item existing is NOT proof of a grade.
                     */
                    'hasgraderecord' =>
                        $grade instanceof \grade_grade,

                    'finalgrade' =>
                        (
                            $grade instanceof \grade_grade &&
                            $grade->finalgrade !== null
                        )
                            ? (float)$grade->finalgrade
                            : null,

                    'rawgrade' =>
                        (
                            $grade instanceof \grade_grade &&
                            $grade->rawgrade !== null
                        )
                            ? (float)$grade->rawgrade
                            : null,

                    'feedback' =>
                        (
                            $grade instanceof \grade_grade &&
                            $grade->feedback !== null
                        )
                            ? trim(
                                strip_tags(
                                    (string)$grade->feedback
                                )
                            )
                            : '',

                    'dategraded' =>
                        (
                            $grade instanceof \grade_grade
                        )
                            ? (
                                $grade->get_dategraded()
                                    ? (int)$grade->get_dategraded()
                                    : null
                            )
                            : null,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            return [];
        }
    }
}
