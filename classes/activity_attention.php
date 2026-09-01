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
 * Read-only role-aware Moodle activity indicators.
 *
 * Converts real Moodle activity conditions and current state into
 * evidence that the assistant can reason over.
 *
 * This class NEVER changes grades, completion, submissions,
 * discussions, or any other Moodle data.
 */
final class activity_attention {

    private \stdClass $course;
    private \context_course $coursecontext;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext = \context_course::instance($course->id);
    }

    /**
     * Build role-aware activity indicators.
     *
     * @return array<string, mixed>
     */
    public function build(): array {
        global $CFG, $USER;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/querylib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $persona = $this->persona();

        return [
            'persona' => $persona,
            'roleperspective' => $persona === 'participant'
                ? 'participant_progress'
                : 'teacher_attention',
            'activities' => $this->activities($persona),
        ];
    }

    /**
     * Read all visible activities and interpret their conditions.
     *
     * @param string $persona
     * @return array<int, array<string, mixed>>
     */
    private function activities(string $persona): array {
        global $DB, $USER;

        $completion = new \completion_info($this->course);
        $modinfo = get_fast_modinfo($this->course, $USER->id);
        $items = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (
                !$cm->uservisible &&
                !has_capability(
                    'moodle/course:viewhiddenactivities',
                    $this->coursecontext,
                    $USER
                )
            ) {
                continue;
            }

            $modulecontext = \context_module::instance($cm->id);

            $cmrecord = $DB->get_record(
                'course_modules',
                ['id' => $cm->id],
                'id,availability,completion,completionview,' .
                'completionexpected,completionpassgrade,' .
                'completiongradeitemnumber'
            );

            if (!$cmrecord) {
                continue;
            }

            $record = null;
            $table = new \xmldb_table($cm->modname);

            if ($DB->get_manager()->table_exists($table)) {
                $record = $DB->get_record(
                    $cm->modname,
                    ['id' => $cm->instance]
                );
            }

            $conditions = $this->completion_conditions(
                $cmrecord,
                $record
            );

            $item = [
                'cmid' => (int)$cm->id,
                'instanceid' => (int)$cm->instance,
                'name' => format_string(
                    $cm->name,
                    true,
                    ['context' => $modulecontext]
                ),
                'modname' => (string)$cm->modname,
                'url' => !empty($cm->url)
                    ? $cm->url->out(false)
                    : '',
                'available' => (bool)$cm->available,
                'uservisible' => (bool)$cm->uservisible,

                /*
                 * Moodle availability restrictions.
                 *
                 * availableinfo is Moodle's own human-readable
                 * explanation for the current user's access state.
                 * availabilityjson is retained as structured evidence
                 * for future condition-aware reasoning.
                 */
                'restrictions' => [
                    'hasrestrictions' =>
                        trim((string)($cmrecord->availability ?? '')) !== '',
                    'available' => (bool)$cm->available,
                    'uservisible' => (bool)$cm->uservisible,
                    'availableinfo' =>
                        $this->availability_info($cm),
                    'availabilityjson' =>
                        (string)($cmrecord->availability ?? ''),
                ],

                'conditions' => $conditions,
            ];

            if ($persona === 'participant') {
                $item['participant'] =
                    $this->participant_state(
                        $cm,
                        $completion,
                        $conditions
                    );
            } else {
                $item['teacher'] =
                    $this->teacher_state(
                        $cm,
                        $record,
                        $conditions
                    );
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Capture Moodle completion conditions.
     *
     * Known module-specific completion fields are preserved.
     * This is intentionally generic so other Moodle modules can
     * contribute completion* fields without hard-coding every plugin.
     *
     * @param \stdClass $cmrecord
     * @param \stdClass|null $record
     * @return array<string, mixed>
     */
    private function completion_conditions(
        \stdClass $cmrecord,
        ?\stdClass $record
    ): array {
        $conditions = [
            'tracking' => (int)($cmrecord->completion ?? 0),
            'viewrequired' =>
                !empty($cmrecord->completionview),
            'passgraderequired' =>
                !empty($cmrecord->completionpassgrade),
            'gradeitemnumber' =>
                isset($cmrecord->completiongradeitemnumber)
                    ? (int)$cmrecord->completiongradeitemnumber
                    : null,
            'expected' =>
                (int)($cmrecord->completionexpected ?? 0),
            'custom' => [],
        ];

        if (!$record) {
            return $conditions;
        }

        foreach (get_object_vars($record) as $name => $value) {
            if (strpos($name, 'completion') !== 0) {
                continue;
            }

            if (
                in_array(
                    $name,
                    [
                        'completion',
                        'completionview',
                        'completionexpected',
                        'completionpassgrade',
                        'completiongradeitemnumber',
                    ],
                    true
                )
            ) {
                continue;
            }

            if (
                $value === null ||
                $value === '' ||
                $value === 0 ||
                $value === '0'
            ) {
                continue;
            }

            $conditions['custom'][$name] = $value;
        }

        return $conditions;
    }

    /**
     * Return Moodle's own readable availability explanation.
     *
     * This is preferable to asking the AI to interpret raw
     * availability JSON on its own.
     */
    private function availability_info(
        \cm_info $cm
    ): string {
        if (empty($cm->availableinfo)) {
            return '';
        }

        try {
            $formatted =
                \core_availability\info_module::format_info(
                    $cm->availableinfo,
                    $this->course
                );

            return trim(
                html_to_text(
                    (string)$formatted,
                    0,
                    false
                )
            );

        } catch (\Throwable $e) {
            /*
             * Availability intelligence must never break
             * the Moodle page or assistant.
             */
            debugging(
                'AI Course Assistant could not format availability information: ' .
                $e->getMessage(),
                DEBUG_DEVELOPER
            );

            return trim(
                html_to_text(
                    (string)$cm->availableinfo,
                    0,
                    false
                )
            );
        }
    }

    /**
     * Participant-side interpretation.
     *
     * @param \cm_info $cm
     * @param \completion_info $completion
     * @param array $conditions
     * @return array<string, mixed>
     */
    private function participant_state(
        \cm_info $cm,
        \completion_info $completion,
        array $conditions
    ): array {
        global $USER;

        $state = [
            'complete' => null,
            'completionstate' => null,
            'needsattention' => false,
            'indicators' => [],
        ];

        if ((int)($conditions['tracking'] ?? 0) > 0) {
            $data = $completion->get_data(
                $cm,
                false,
                $USER->id
            );

            $completionstate =
                (int)($data->completionstate ?? COMPLETION_INCOMPLETE);

            $complete = in_array(
                $completionstate,
                [
                    COMPLETION_COMPLETE,
                    COMPLETION_COMPLETE_PASS,
                ],
                true
            );

            $state['complete'] = $complete;
            $state['completionstate'] = $completionstate;

            if (!$complete) {
                $state['needsattention'] = true;
                $state['indicators'][] =
                    'Activity completion conditions remain incomplete.';
            }
        }

        if (!$cm->available || !$cm->uservisible) {
            $state['needsattention'] = true;

            $availableinfo =
                $this->availability_info($cm);

            if ($availableinfo !== '') {
                $state['indicators'][] =
                    'Access restriction: ' . $availableinfo;
            } else {
                $state['indicators'][] =
                    'Access or availability conditions currently apply.';
            }
        }

        return $state;
    }

    /**
     * Teacher-side interpretation.
     *
     * Conditions are indicators, but we do not claim outstanding
     * teacher work unless current Moodle state supports it.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $record
     * @param array $conditions
     * @return array<string, mixed>
     */
    private function teacher_state(
        \cm_info $cm,
        ?\stdClass $record,
        array $conditions
    ): array {
        $state = [
            'needsattention' => false,
            'indicators' => [],
            'grading' => null,
            'forum' => null,
        ];

        /*
         * A grade-related completion condition is an indicator that
         * grading matters for this activity.
         */
        if (
            !empty($conditions['passgraderequired']) ||
            $this->has_grade_condition($conditions)
        ) {
            $state['indicators'][] =
                'This activity has a grade-related condition.';
        }

        if ($cm->modname === 'forum') {
            $state['forum'] =
                $this->forum_teacher_state(
                    $cm,
                    $record
                );

            if (
                !empty(
                    $state['forum']['posts_without_my_response']
                ) ||
                !empty(
                    $state['forum']['posts_with_no_replies']
                )
            ) {
                $state['needsattention'] = true;
            }
        }

        /*
         * Check actual gradebook state for any gradeable activity.
         */
        $state['grading'] =
            $this->grading_state($cm);

        if (
            !empty(
                $state['grading']['users_without_grade']
            )
        ) {
            $state['needsattention'] = true;
        }

        return $state;
    }

    /**
     * Forum-specific teacher indicators.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $forum
     * @return array<string, mixed>|null
     */
    private function forum_teacher_state(
        \cm_info $cm,
        ?\stdClass $forum
    ): ?array {
        global $DB, $USER;

        if (!$forum) {
            return null;
        }

        $context =
            \context_module::instance($cm->id);

        if (
            !has_capability(
                'mod/forum:viewdiscussion',
                $context,
                $USER
            )
        ) {
            return null;
        }

        $params = [
            'forumid' => (int)$forum->id,
        ];

        $groupsql = '';

        if (
            groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability(
                'moodle/site:accessallgroups',
                $context,
                $USER
            )
        ) {
            $groupids = array_keys(
                groups_get_all_groups(
                    $this->course->id,
                    $USER->id,
                    $cm->groupingid
                )
            );

            $groupids = array_values(
                array_unique(
                    array_map('intval', $groupids)
                )
            );

            $groupids[] = 0;

            [$insql, $inparams] =
                $DB->get_in_or_equal(
                    $groupids,
                    SQL_PARAMS_NAMED,
                    'attngroup'
                );

            $groupsql =
                " AND d.groupid {$insql}";

            $params =
                array_merge(
                    $params,
                    $inparams
                );
        }

        $discussions =
            $DB->get_records_sql(
                "SELECT d.id,
                        d.name,
                        d.userid
                   FROM {forum_discussions} d
                  WHERE d.forum = :forumid
                        {$groupsql}
               ORDER BY d.timemodified DESC",
                $params
            );

        $items = [];
        $withoutmyresponse = 0;
        $withnoreplies = 0;

        foreach ($discussions as $discussion) {

            $posts =
                $DB->get_records(
                    'forum_posts',
                    [
                        'discussion' =>
                            (int)$discussion->id,
                    ],
                    'created ASC, id ASC',
                    'id,userid,parent,subject,message,messageformat,created'
                );

            if (!$posts) {
                continue;
            }

            /*
             * Build exact Moodle parent -> children relationships.
             */
            $children = [];

            foreach ($posts as $candidate) {
                $parentid =
                    (int)$candidate->parent;

                if (!isset($children[$parentid])) {
                    $children[$parentid] = [];
                }

                $children[$parentid][] =
                    (int)$candidate->id;
            }

            /*
             * Locate the actual discussion root.
             */
            $root = null;

            foreach ($posts as $candidate) {
                if ((int)$candidate->parent === 0) {
                    $root = $candidate;
                    break;
                }
            }

            if (!$root) {
                continue;
            }

            /*
             * AUTHORITATIVE CONTRIBUTION MODEL
             *
             * Participant-created discussion:
             *     The root post is the contribution.
             *     Every reply underneath belongs to its conversation.
             *
             * Teacher-created discussion:
             *     Each direct participant reply to the root is an
             *     independent participant contribution.
             *
             * A peer reply beneath another participant's contribution
             * NEVER becomes another teacher reply obligation.
             */
            $targets = [];

            if (
                !$this->is_teacher_user(
                    (int)$root->userid
                )
            ) {
                $targets[] = $root;

            } else {
                foreach (
                    $children[(int)$root->id] ?? []
                    as $childid
                ) {
                    if (!isset($posts[$childid])) {
                        continue;
                    }

                    $child =
                        $posts[$childid];

                    if (
                        (int)$child->userid ===
                            (int)$USER->id
                    ) {
                        continue;
                    }

                    if (
                        $this->is_teacher_user(
                            (int)$child->userid
                        )
                    ) {
                        continue;
                    }

                    $targets[] = $child;
                }
            }

            foreach ($targets as $post) {

                $userid =
                    (int)$post->userid;

                if ($userid <= 0) {
                    continue;
                }

                if (
                    $userid === (int)$USER->id ||
                    $this->is_teacher_user($userid)
                ) {
                    continue;
                }

                /*
                 * Walk the complete descendant branch.
                 *
                 * There is no depth limit:
                 * reply
                 * reply to reply
                 * reply to reply to reply
                 * etc.
                 */
                $stack =
                    $children[(int)$post->id]
                    ?? [];

                $seen = [];
                $hasanyreply = false;
                $hasmyresponse = false;

                while ($stack) {

                    $descendantid =
                        (int)array_pop($stack);

                    if (
                        isset($seen[$descendantid])
                    ) {
                        continue;
                    }

                    $seen[$descendantid] = true;

                    if (
                        !isset($posts[$descendantid])
                    ) {
                        continue;
                    }

                    $descendant =
                        $posts[$descendantid];

                    $hasanyreply = true;

                    if (
                        (int)$descendant->userid ===
                        (int)$USER->id
                    ) {
                        $hasmyresponse = true;
                        break;
                    }

                    foreach (
                        $children[$descendantid] ?? []
                        as $childid
                    ) {
                        $stack[] =
                            (int)$childid;
                    }
                }

                if (!$hasanyreply) {
                    $withnoreplies++;
                }

                /*
                 * If the current verified teacher appears anywhere
                 * beneath this contribution, it is already handled.
                 */
                if ($hasmyresponse) {
                    continue;
                }

                $withoutmyresponse++;

                $author =
                    $DB->get_record(
                        'user',
                        ['id' => $userid],
                        'id,firstname,lastname',
                        IGNORE_MISSING
                    );

                $authorname =
                    $author
                        ? fullname($author)
                        : get_string('unknownuser');

                $posturl =
                    (
                        new \moodle_url(
                            '/mod/forum/discuss.php',
                            [
                                'd' =>
                                    (int)$discussion->id,
                            ],
                            'p' . (int)$post->id
                        )
                    )->out(false);

                $items[] = [
                    'postid' =>
                        (int)$post->id,

                    'discussionid' =>
                        (int)$discussion->id,

                    'discussionname' =>
                        format_string(
                            (string)$discussion->name,
                            true,
                            ['context' => $context]
                        ),

                    'subject' =>
                        format_string(
                            (string)$post->subject,
                            true,
                            ['context' => $context]
                        ),

                    'userid' =>
                        $userid,

                    'author' =>
                        $authorname,

                    'message' =>
                        trim(
                            html_to_text(
                                format_text(
                                    (string)$post->message,
                                    (int)$post->messageformat,
                                    [
                                        'context' => $context,
                                        'para' => false,
                                        'filter' => true,
                                    ]
                                ),
                                0,
                                false
                            )
                        ),

                    'hasanyreply' =>
                        $hasanyreply,

                    'hasmyresponse' =>
                        false,

                    'responsebasis' =>
                        'moodle_contribution_root',

                    /*
                     * Exact permalink generated by Moodle.
                     * Never reconstructed by the AI.
                     */
                    'url' =>
                        $posturl,
                ];
            }
        }

        return [
            'posts_without_my_response' =>
                $withoutmyresponse,

            'posts_with_no_replies' =>
                $withnoreplies,

            'items' =>
                $items,

            'responsemodel' =>
                'moodle_contribution_root',
        ];
    }

    /**
     * Determine which participants have actual work in an activity.
     *
     * A blank grade for an enrolled participant is NOT by itself
     * evidence that the teacher has something to grade.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>
     */
    private function activity_work_users(
        \cm_info $cm
    ): array {
        global $DB, $USER;

        $userids = [];
        $basis = 'unsupported';

        /*
         * Forum:
         * work exists when the participant has actually posted
         * in this forum.
         */
        if ($cm->modname === 'forum') {
            $context = \context_module::instance($cm->id);

            $params = [
                'forumid' => (int)$cm->instance,
            ];

            $groupsql = '';

            if (
                groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
                !has_capability(
                    'moodle/site:accessallgroups',
                    $context,
                    $USER
                )
            ) {
                $groupids = array_keys(
                    groups_get_all_groups(
                        $this->course->id,
                        $USER->id,
                        $cm->groupingid
                    )
                );

                $groupids = array_values(
                    array_unique(
                        array_map('intval', $groupids)
                    )
                );

                // Group 0 discussions are visible to everyone.
                $groupids[] = 0;

                [$insql, $inparams] =
                    $DB->get_in_or_equal(
                        $groupids,
                        SQL_PARAMS_NAMED,
                        'gradeworkgroup'
                    );

                $groupsql = " AND d.groupid {$insql}";
                $params = array_merge(
                    $params,
                    $inparams
                );
            }

            $records = $DB->get_records_sql(
                "SELECT DISTINCT p.userid
                   FROM {forum_discussions} d
                   JOIN {forum_posts} p
                     ON p.discussion = d.id
                  WHERE d.forum = :forumid
                        {$groupsql}
                    AND p.userid > 0",
                $params
            );

            foreach ($records as $record) {
                $userid = (int)$record->userid;

                if (
                    $userid > 0 &&
                    !$this->is_teacher_user($userid)
                ) {
                    $userids[$userid] = $userid;
                }
            }

            $basis = 'forum_posts';
        }

        /*
         * Assignment:
         * work exists when Moodle has a submitted assignment.
         */
        if ($cm->modname === 'assign') {
            $table = new \xmldb_table('assign_submission');

            if ($DB->get_manager()->table_exists($table)) {
                $records = $DB->get_records(
                    'assign_submission',
                    [
                        'assignment' => (int)$cm->instance,
                        'status' => 'submitted',
                    ],
                    '',
                    'id,userid'
                );

                foreach ($records as $record) {
                    $userid = (int)$record->userid;

                    if (
                        $userid > 0 &&
                        !$this->is_teacher_user($userid)
                    ) {
                        $userids[$userid] = $userid;
                    }
                }

                $basis = 'assignment_submissions';
            }
        }

        return [
            'basis' => $basis,
            'userids' => array_values($userids),
        ];
    }

    /**
     * Gradebook state for an activity with actual participant work.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>|null
     */
    private function grading_state(
        \cm_info $cm
    ): ?array {
        global $DB, $USER;

        if (
            !has_capability(
                'moodle/grade:viewall',
                $this->coursecontext,
                $USER
            ) &&
            !has_capability(
                'moodle/grade:edit',
                $this->coursecontext,
                $USER
            )
        ) {
            return null;
        }

        $gradeitems =
            \grade_get_grade_items_for_activity(
                $cm,
                true
            );

        if (!$gradeitems) {
            return null;
        }

        /*
         * Critical distinction:
         *
         * Do NOT take every enrolled participant with a blank grade.
         * First establish that Moodle contains work from that user
         * in this specific activity.
         */
        $work =
            $this->activity_work_users($cm);

        $userids =
            is_array($work['userids'] ?? null)
                ? $work['userids']
                : [];

        $basis =
            (string)($work['basis'] ?? 'unsupported');

        /*
         * We do not claim a grading queue for activity types for
         * which this service cannot yet verify submitted work.
         */
        if ($basis === 'unsupported') {
            return [
                'gradeable' => true,
                'workstateknown' => false,
                'evidencebasis' => 'unsupported',
                'users_with_work' => null,
                'users_without_grade' => null,
                'users_with_grade' => null,
                'ungraded_users' => [],
            ];
        }

        if (!$userids) {
            return [
                'gradeable' => true,
                'workstateknown' => true,
                'evidencebasis' => $basis,
                'users_with_work' => 0,
                'users_without_grade' => 0,
                'users_with_grade' => 0,
                'ungraded_users' => [],
            ];
        }

        $grades =
            \grade_get_grades(
                $this->course->id,
                'mod',
                $cm->modname,
                $cm->instance,
                $userids
            );

        $gradeentries = [];

        if (
            !empty($grades->items) &&
            !empty($grades->items[0]->grades)
        ) {
            $gradeentries =
                $grades->items[0]->grades;
        }

        $withgrade = 0;
        $withoutgrade = 0;
        $ungradedusers = [];

        foreach ($userids as $userid) {
            $grade =
                $gradeentries[$userid] ?? null;

            /*
             * Moodle grade_get_grades returns a grade object whose
             * rawgrade is the safest indication that a grade exists.
             * Some output objects also expose grade/formatted grade.
             */
            $hasgrade = false;

            if ($grade) {
                if (
                    property_exists($grade, 'rawgrade') &&
                    $grade->rawgrade !== null &&
                    $grade->rawgrade !== ''
                ) {
                    $hasgrade = true;
                } else if (
                    property_exists($grade, 'grade') &&
                    $grade->grade !== null &&
                    $grade->grade !== ''
                    &&
                    $grade->grade !== '-'
                ) {
                    $hasgrade = true;
                }
            }

            if ($hasgrade) {
                $withgrade++;
                continue;
            }

            $withoutgrade++;

            $user = $DB->get_record(
                'user',
                ['id' => $userid],
                'id,firstname,lastname',
                IGNORE_MISSING
            );

            $ungradedusers[] = [
                'userid' => (int)$userid,
                'name' => $user
                    ? fullname($user)
                    : get_string('unknownuser'),
            ];
        }

        return [
            'gradeable' => true,
            'workstateknown' => true,
            'evidencebasis' => $basis,
            'users_with_work' => count($userids),
            'users_without_grade' => $withoutgrade,
            'users_with_grade' => $withgrade,
            'ungraded_users' => $ungradedusers,
        ];
    }

    /**
     * Whether custom completion data contains a grade rule.
     */
    private function has_grade_condition(
        array $conditions
    ): bool {
        foreach (
            array_keys(
                $conditions['custom'] ?? []
            ) as $name
        ) {
            if (
                stripos(
                    (string)$name,
                    'grade'
                ) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current user's Moodle persona.
     */
    private function persona(): string {
        global $USER;

        $roles = get_user_roles(
            $this->coursecontext,
            $USER->id,
            true
        );

        $rolenames = [];

        foreach ($roles as $role) {
            $name = role_get_name(
                $role,
                $this->coursecontext,
                ROLENAME_ORIGINAL
            );

            if ($name !== '') {
                $rolenames[] = $name;
            }
        }

        $lower =
            \core_text::strtolower(
                implode(' ', $rolenames)
            );

        if (
            has_capability(
                'moodle/course:update',
                $this->coursecontext,
                $USER
            ) ||
            has_capability(
                'moodle/course:manageactivities',
                $this->coursecontext,
                $USER
            )
        ) {
            return 'editing_teacher';
        }

        if (
            has_capability(
                'moodle/grade:viewall',
                $this->coursecontext,
                $USER
            ) ||
            has_capability(
                'moodle/grade:edit',
                $this->coursecontext,
                $USER
            ) ||
            preg_match(
                '/non.?editing teacher|teacher/u',
                $lower
            )
        ) {
            return 'teacher';
        }

        if (
            preg_match('/\bmanager\b/u', $lower)
        ) {
            return 'manager';
        }

        return 'participant';
    }

    /**
     * Whether another enrolled user is teaching staff.
     */
    private function is_teacher_user(
        int $userid
    ): bool {
        if ($userid <= 0) {
            return false;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        return
            has_capability(
                'moodle/course:update',
                $this->coursecontext,
                $userid,
                false
            ) ||
            has_capability(
                'moodle/course:manageactivities',
                $this->coursecontext,
                $userid,
                false
            ) ||
            has_capability(
                'moodle/grade:viewall',
                $this->coursecontext,
                $userid,
                false
            ) ||
            has_capability(
                'moodle/grade:edit',
                $this->coursecontext,
                $userid,
                false
            );
    }
}
