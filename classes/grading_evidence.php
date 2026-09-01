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
 * Read-only authoritative Moodle grading configuration.
 *
 * Provides the AI with the actual grading policy Moodle is using,
 * rather than forcing the model to infer grading from course prose.
 *
 * This service never changes grades.
 */
final class grading_evidence {

    private \stdClass $course;
    private \context_course $coursecontext;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext =
            \context_course::instance($course->id);
    }

    /**
     * Build grading configuration for visible gradeable activities.
     *
     * @return array<string, mixed>
     */
    public function build(): array {
        global $CFG, $DB, $USER;

        require_once(
            $CFG->libdir . '/gradelib.php'
        );

        require_once(
            $CFG->dirroot . '/grade/querylib.php'
        );

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $activities = [];

        foreach ($modinfo->get_cms() as $cm) {

            if (!$cm->uservisible) {
                continue;
            }

            $items =
                \grade_get_grade_items_for_activity(
                    $cm,
                    false
                );

            if (!$items) {
                continue;
            }

            $gradeitems = [];

            foreach ($items as $item) {

                $scale = null;

                if (
                    (int)$item->gradetype === GRADE_TYPE_SCALE &&
                    !empty($item->scaleid)
                ) {
                    $scalerecord =
                        $DB->get_record(
                            'scale',
                            ['id' => (int)$item->scaleid],
                            'id,name,scale',
                            IGNORE_MISSING
                        );

                    if ($scalerecord) {
                        $scale = [
                            'id' =>
                                (int)$scalerecord->id,
                            'name' =>
                                (string)$scalerecord->name,
                            'items' =>
                                array_map(
                                    'trim',
                                    explode(
                                        ',',
                                        (string)$scalerecord->scale
                                    )
                                ),
                        ];
                    }
                }

                $gradeitems[] = [
                    'gradeitemid' =>
                        (int)$item->id,

                    'itemname' =>
                        (string)($item->itemname ?? ''),

                    'itemnumber' =>
                        (int)($item->itemnumber ?? 0),

                    'gradetype' =>
                        $this->grade_type_name(
                            (int)$item->gradetype
                        ),

                    'grademin' =>
                        (float)$item->grademin,

                    'grademax' =>
                        (float)$item->grademax,

                    /*
                     * Moodle's configured grade required to pass.
                     * A value of 0 means no explicit grade-to-pass
                     * threshold is configured on this grade item.
                     */
                    'gradepass' =>
                        (float)$item->gradepass,

                    'scale' =>
                        $scale,

                    'hidden' =>
                        !empty($item->hidden),

                    'locked' =>
                        !empty($item->locked),
                ];
            }

            if (!$gradeitems) {
                continue;
            }

            $cmrecord =
                $DB->get_record(
                    'course_modules',
                    ['id' => $cm->id],
                    'id,completion,completiongradeitemnumber,completionpassgrade',
                    IGNORE_MISSING
                );

            $completion = [
                'enabled' =>
                    $cmrecord
                        ? (int)$cmrecord->completion > 0
                        : false,

                'gradeitemnumber' =>
                    $cmrecord &&
                    $cmrecord->completiongradeitemnumber !== null
                        ? (int)$cmrecord->completiongradeitemnumber
                        : null,

                'requirespassinggrade' =>
                    $cmrecord
                        ? !empty($cmrecord->completionpassgrade)
                        : false,
            ];

            $activityconfig =
                $this->activity_specific_config($cm);

            $activities[] = [
                'cmid' =>
                    (int)$cm->id,

                'modname' =>
                    (string)$cm->modname,

                'instanceid' =>
                    (int)$cm->instance,

                'name' =>
                    format_string(
                        $cm->name,
                        true,
                        ['context' => $cm->context]
                    ),

                'url' =>
                    $cm->url
                        ? $cm->url->out(false)
                        : '',

                'gradeitems' =>
                    $gradeitems,

                'completiongrading' =>
                    $completion,

                'activityconfiguration' =>
                    $activityconfig,
            ];
        }

        return [
            'activities' => $activities,
        ];
    }

    /**
     * Activity-specific grading settings Moodle exposes.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>
     */
    private function activity_specific_config(
        \cm_info $cm
    ): array {
        global $DB;

        if ($cm->modname === 'forum') {

            $forum =
                $DB->get_record(
                    'forum',
                    ['id' => $cm->instance],
                    'id,assessed,scale,grade_forum,completiondiscussions,completionreplies,completionposts',
                    IGNORE_MISSING
                );

            if (!$forum) {
                return [];
            }

            return [
                'type' => 'forum',

                'ratingsenabled' =>
                    (int)$forum->assessed !== 0,

                'ratingsaggregation' =>
                    (int)$forum->assessed,

                'ratingscale' =>
                    (int)$forum->scale,

                'wholeforumgrade' =>
                    (int)$forum->grade_forum,

                'completiondiscussions' =>
                    (int)$forum->completiondiscussions,

                'completionreplies' =>
                    (int)$forum->completionreplies,

                'completionposts' =>
                    (int)$forum->completionposts,
            ];
        }

        return [];
    }

    /**
     * Human-readable Moodle grade type.
     */
    private function grade_type_name(
        int $type
    ): string {
        return match ($type) {
            GRADE_TYPE_NONE => 'none',
            GRADE_TYPE_VALUE => 'value',
            GRADE_TYPE_SCALE => 'scale',
            GRADE_TYPE_TEXT => 'text',
            default => 'unknown',
        };
    }
}
