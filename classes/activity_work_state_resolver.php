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
 * Universal pedagogical work-state resolver.
 *
 * This layer answers a different question from raw grading evidence:
 *
 * What stage is the work actually in?
 *
 * Examples:
 *
 * ungraded
 * waiting_for_participant
 * ready_for_review
 * draft
 * submitted
 * reopened
 * graded
 * released
 * attempt_in_progress
 * attempt_finished
 *
 * Specialist module evidence is used where Moodle provides real
 * workflow semantics. Unknown activities remain visible but are not
 * assigned invented workflow states.
 *
 * Read only.
 *
 * @package local_courseaiassistant
 */
final class activity_work_state_resolver {

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
     * Resolve workflow evidence only for relevant requests.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return array<string, mixed>
     */
    public function resolve(
        string $question,
        array $history = []
    ): array {
        if (
            !$this->request_needs_workflow(
                $question,
                $history
            )
        ) {
            return [
                'active' =>
                    false,

                'activities' =>
                    [],
            ];
        }

        if (!$this->can_inspect_work()) {
            return [
                'active' =>
                    false,

                'reason' =>
                    'insufficient_permission',

                'activities' =>
                    [],
            ];
        }

        global $USER;

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $activities = [];

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

            switch ((string)$cm->modname) {
                case 'assign':
                    $state =
                        $this->assignment_state(
                            $cm,
                            $context
                        );
                    break;

                case 'quiz':
                    $state =
                        $this->quiz_state(
                            $cm,
                            $context
                        );
                    break;

                default:
                    $state = null;
                    break;
            }

            if ($state !== null) {
                $activities[] = $state;
            }
        }

        /*
         * Forum remains specialist and authoritative through the
         * Batch 9 forum workflow resolver.
         */
        $forumstate =
            (
                new forum_work_state_resolver(
                    $this->course
                )
            )->resolve(
                $question,
                $history
            );

        return [
            'active' =>
                true,

            'forums' =>
                !empty($forumstate['active'])
                    ? (
                        $forumstate['forums']
                        ?? []
                    )
                    : [],

            'activities' =>
                $activities,
        ];
    }

    /**
     * Workflow-related language.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return bool
     */
    private function request_needs_workflow(
        string $question,
        array $history
    ): bool {
        $text =
            \core_text::strtolower(
                trim(
                    $question . ' ' .
                    $this->history_text($history)
                )
            );

        if ($text === '') {
            return false;
        }

        return (bool)preg_match(
            '/\b(' .
            'grade|grading|graded|ungraded|' .
            'review|reviewing|' .
            'submission|submitted|draft|' .
            'attempt|attempted|' .
            'reopen|reopened|' .
            'revise|revision|redo|resubmit|' .
            'ready|waiting|' .
            'feedback|' .
            'complete|incomplete|' .
            'what needs|who needs|what is left|' .
            'still needs|still need' .
            ')\b/u',
            $text
        );
    }

    /**
     * Recent conversational task context.
     *
     * @param array<int, mixed> $history
     * @return string
     */
    private function history_text(
        array $history
    ): string {
        $parts = [];

        foreach (
            array_slice(
                $history,
                -4
            ) as $entry
        ) {
            if (is_string($entry)) {
                $parts[] = $entry;
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            foreach (
                [
                    'message',
                    'content',
                    'text',
                    'question',
                    'answer',
                ] as $key
            ) {
                if (
                    isset($entry[$key]) &&
                    is_string($entry[$key])
                ) {
                    $parts[] =
                        $entry[$key];
                }
            }
        }

        return implode(
            ' ',
            $parts
        );
    }

    /**
     * Teacher/manager/admin authority.
     *
     * @return bool
     */
    private function can_inspect_work(): bool {
        return
            is_siteadmin() ||
            has_capability(
                'moodle/grade:viewall',
                $this->coursecontext
            ) ||
            has_capability(
                'moodle/grade:edit',
                $this->coursecontext
            ) ||
            has_capability(
                'moodle/course:manageactivities',
                $this->coursecontext
            );
    }

    /**
     * Assignment pedagogical workflow.
     *
     * Uses Moodle's actual submission and grading workflow fields.
     *
     * A submission is not automatically "needs grading".
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>|null
     */
    private function assignment_state(
        \cm_info $cm,
        \context_module $context
    ): ?array {
        global $CFG, $DB;

        if (
            !has_capability(
                'mod/assign:grade',
                $context
            )
        ) {
            return null;
        }

        require_once(
            $CFG->dirroot .
            '/mod/assign/locallib.php'
        );

        $assignment =
            $DB->get_record(
                'assign',
                [
                    'id' =>
                        (int)$cm->instance,
                ],
                '*',
                IGNORE_MISSING
            );

        if (!$assignment) {
            return null;
        }

        /*
         * Latest submission only.
         */
        $submissions =
            $DB->get_records(
                'assign_submission',
                [
                    'assignment' =>
                        (int)$cm->instance,

                    'latest' =>
                        1,
                ],
                'userid ASC'
            );

        $grades =
            $DB->get_records(
                'assign_grades',
                [
                    'assignment' =>
                        (int)$cm->instance,
                ]
            );

        $grademap = [];

        foreach ($grades as $grade) {
            $grademap[(int)$grade->userid] =
                $grade;
        }

        $users = [];

        foreach ($submissions as $submission) {
            $userid =
                (int)$submission->userid;

            if ($userid <= 0) {
                continue;
            }

            $user =
                $DB->get_record(
                    'user',
                    [
                        'id' =>
                            $userid,
                    ],
                    'id,firstname,lastname',
                    IGNORE_MISSING
                );

            $grade =
                $grademap[$userid]
                ?? null;

            $status =
                (string)(
                    $submission->status
                    ?? ''
                );

            $workflowstate =
                $grade
                    ? (
                        (string)(
                            $grade->workflowstate
                            ?? ''
                        )
                    )
                    : '';

            $gradevalue =
                $grade &&
                $grade->grade !== null &&
                (float)$grade->grade >= 0
                    ? (float)$grade->grade
                    : null;

            $workflow =
                $this->assignment_workflow(
                    $status,
                    $workflowstate,
                    $gradevalue,
                    (int)(
                        $submission->attemptnumber
                        ?? 0
                    ),
                    $grade
                        ? (int)(
                            $grade->attemptnumber
                            ?? 0
                        )
                        : -1
                );

            $users[] = [
                'userid' =>
                    $userid,

                'participant' =>
                    $user
                        ? fullname($user)
                        : get_string(
                            'unknownuser'
                        ),

                'submissionid' =>
                    (int)$submission->id,

                'submissionstatus' =>
                    $status,

                'attemptnumber' =>
                    (int)(
                        $submission->attemptnumber
                        ?? 0
                    ),

                'timecreated' =>
                    (int)(
                        $submission->timecreated
                        ?? 0
                    ),

                'timemodified' =>
                    (int)(
                        $submission->timemodified
                        ?? 0
                    ),

                'grade' =>
                    $gradevalue,

                'gradeattemptnumber' =>
                    $grade
                        ? (int)(
                            $grade->attemptnumber
                            ?? -1
                        )
                        : null,

                'workflowstate' =>
                    $workflowstate,

                'workstate' =>
                    $workflow,

                'graderurl' =>
                    (
                        new \moodle_url(
                            '/mod/assign/view.php',
                            [
                                'id' =>
                                    (int)$cm->id,

                                'action' =>
                                    'grader',

                                'userid' =>
                                    $userid,
                            ]
                        )
                    )->out(false),
            ];
        }

        return [
            'cmid' =>
                (int)$cm->id,

            'module' =>
                'assign',

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

            'markingworkflowenabled' =>
                !empty(
                    $assignment->markingworkflow
                ),

            'attemptreopenmethod' =>
                (string)(
                    $assignment->attemptreopenmethod
                    ?? ''
                ),

            'users' =>
                $users,
        ];
    }

    /**
     * Determine Assignment work state from Moodle fields.
     *
     * @param string $submissionstatus
     * @param string $workflowstate
     * @param float|null $grade
     * @param int $submissionattempt
     * @param int $gradeattempt
     * @return string
     */
    private function assignment_workflow(
        string $submissionstatus,
        string $workflowstate,
        ?float $grade,
        int $submissionattempt,
        int $gradeattempt
    ): string {
        /*
         * Marking workflow is explicit Moodle state and wins.
         */
        $knownworkflowstates = [
            'notmarked',
            'inmarking',
            'readyforreview',
            'inreview',
            'readyforrelease',
            'released',
        ];

        if (
            in_array(
                $workflowstate,
                $knownworkflowstates,
                true
            )
        ) {
            return $workflowstate;
        }

        if ($submissionstatus === 'draft') {
            return 'draft';
        }

        if ($submissionstatus === 'reopened') {
            return 'waiting_for_participant';
        }

        if ($submissionstatus === 'new') {
            return 'not_submitted';
        }

        if ($submissionstatus === 'submitted') {
            /*
             * Grade belongs to a previous attempt:
             * current resubmission needs fresh review.
             */
            if (
                $submissionattempt >
                $gradeattempt
            ) {
                return 'ready_for_review';
            }

            if ($grade !== null) {
                return 'graded';
            }

            return 'ready_for_review';
        }

        return 'unknown';
    }

    /**
     * Quiz attempt workflow.
     *
     * This does NOT claim manual grading is required.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>|null
     */
    private function quiz_state(
        \cm_info $cm,
        \context_module $context
    ): ?array {
        global $DB;

        if (
            !has_capability(
                'mod/quiz:viewreports',
                $context
            ) &&
            !has_capability(
                'mod/quiz:grade',
                $context
            )
        ) {
            return null;
        }

        $attempts =
            $DB->get_records(
                'quiz_attempts',
                [
                    'quiz' =>
                        (int)$cm->instance,

                    'preview' =>
                        0,
                ],
                'userid ASC, attempt ASC'
            );

        $users = [];

        foreach ($attempts as $attempt) {
            $userid =
                (int)$attempt->userid;

            $user =
                $DB->get_record(
                    'user',
                    [
                        'id' =>
                            $userid,
                    ],
                    'id,firstname,lastname',
                    IGNORE_MISSING
                );

            $state =
                (string)(
                    $attempt->state
                    ?? ''
                );

            $users[] = [
                'userid' =>
                    $userid,

                'participant' =>
                    $user
                        ? fullname($user)
                        : get_string(
                            'unknownuser'
                        ),

                'attemptid' =>
                    (int)$attempt->id,

                'attemptnumber' =>
                    (int)$attempt->attempt,

                'attemptstate' =>
                    $state,

                'workstate' =>
                    $this->quiz_workflow(
                        $state
                    ),

                'sumgrades' =>
                    $attempt->sumgrades !== null
                        ? (float)$attempt->sumgrades
                        : null,

                'timestart' =>
                    (int)$attempt->timestart,

                'timefinish' =>
                    (int)$attempt->timefinish,

                'reviewurl' =>
                    (
                        new \moodle_url(
                            '/mod/quiz/review.php',
                            [
                                'attempt' =>
                                    (int)$attempt->id,
                            ]
                        )
                    )->out(false),
            ];
        }

        return [
            'cmid' =>
                (int)$cm->id,

            'module' =>
                'quiz',

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

            'users' =>
                $users,
        ];
    }

    /**
     * Quiz workflow from Moodle attempt state.
     *
     * @param string $state
     * @return string
     */
    private function quiz_workflow(
        string $state
    ): string {
        switch ($state) {
            case 'inprogress':
                return 'attempt_in_progress';

            case 'overdue':
                return 'attempt_overdue';

            case 'finished':
                return 'attempt_finished';

            case 'abandoned':
                return 'attempt_abandoned';

            default:
                return 'unknown';
        }
    }
}
