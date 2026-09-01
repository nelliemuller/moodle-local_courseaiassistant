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
 * Permission-aware, request-aware participant activity evidence.
 *
 * This resolver is intentionally selective.
 * It does NOT expose all participant state on every request.
 *
 * It activates only when:
 *   1. the current Moodle user has appropriate course authority, and
 *   2. the request actually concerns participants, submissions,
 *      attempts, grades, completion, responses, or named users.
 *
 * Read only.
 *
 * @package local_courseaiassistant
 */
final class participant_activity_state_resolver {

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
     * Resolve only evidence relevant to this request.
     *
     * @param string $question
     * @return array<string, mixed>
     */
    public function resolve(
        string $question
    ): array {
        global $USER;

        if (!$this->request_needs_participant_state($question)) {
            return [
                'active' => false,
                'reason' => 'not_requested',
                'users' => [],
                'activities' => [],
            ];
        }

        if (!$this->can_inspect_participants()) {
            return [
                'active' => false,
                'reason' => 'insufficient_permission',
                'users' => [],
                'activities' => [],
            ];
        }

        $users =
            $this->relevant_users(
                $question
            );

        /*
         * If a specific person is named, inspect only that person.
         *
         * If the request is course-wide, retain a compact roster and
         * activity-state evidence rather than dumping arbitrary
         * profile information.
         */
        return [
            'active' =>
                true,

            'viewerid' =>
                (int)$USER->id,

            'users' =>
                $users,

            'activities' =>
                $this->activity_evidence(
                    $users
                ),
        ];
    }

    /**
     * Does the natural-language request actually need participant data?
     *
     * @param string $question
     * @return bool
     */
    private function request_needs_participant_state(
        string $question
    ): bool {
        $q =
            \core_text::strtolower(
                trim($question)
            );

        if ($q === '') {
            return false;
        }

        return (bool)preg_match(
            '/\b(' .
            'participant|participants|student|students|learner|learners|' .
            'submission|submissions|submitted|attempt|attempts|attempted|' .
            'grade|grades|graded|grading|ungraded|' .
            'complete|completed|completion|incomplete|' .
            'response|responses|responded|' .
            'who|whose|which user|which participant|' .
            'needs reply|need reply|needs grading|need grading' .
            ')\b/u',
            $q
        );
    }

    /**
     * Current user must have real Moodle authority.
     *
     * @return bool
     */
    private function can_inspect_participants(): bool {
        return
            is_siteadmin() ||
            has_capability(
                'moodle/course:viewparticipants',
                $this->coursecontext
            ) &&
            (
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
                )
            );
    }

    /**
     * Identify relevant enrolled users.
     *
     * Exact name matching is preferred when the question contains
     * a participant name. Otherwise return only users with course
     * participation capability.
     *
     * @param string $question
     * @return array<int, array<string, mixed>>
     */
    private function relevant_users(
        string $question
    ): array {
        $users =
            get_enrolled_users(
                $this->coursecontext,
                '',
                0,
                'u.id,u.firstname,u.lastname,u.email',
                'u.lastname ASC, u.firstname ASC'
            );

        $questionlower =
            \core_text::strtolower(
                $question
            );

        $matched = [];

        foreach ($users as $user) {
            if (
                empty($user->id) ||
                isguestuser($user)
            ) {
                continue;
            }

            $fullname =
                fullname($user);

            $namelower =
                \core_text::strtolower(
                    $fullname
                );

            /*
             * A specific name in the question narrows evidence.
             */
            if (
                $namelower !== '' &&
                \core_text::strpos(
                    $questionlower,
                    $namelower
                ) !== false
            ) {
                $matched[(int)$user->id] = [
                    'userid' =>
                        (int)$user->id,

                    'fullname' =>
                        $fullname,

                    'matchedbyname' =>
                        true,
                ];
            }
        }

        if ($matched) {
            return array_values($matched);
        }

        /*
         * Course-wide request.
         *
         * Keep identity evidence compact.
         * Email addresses are deliberately not exposed to AI context.
         */
        $result = [];

        foreach ($users as $user) {
            if (
                empty($user->id) ||
                isguestuser($user)
            ) {
                continue;
            }

            $result[] = [
                'userid' =>
                    (int)$user->id,

                'fullname' =>
                    fullname($user),

                'matchedbyname' =>
                    false,
            ];
        }

        return $result;
    }

    /**
     * Resolve actual activity evidence only where the current
     * teacher/admin has module-level permission.
     *
     * @param array<int, array<string, mixed>> $users
     * @return array<int, array<string, mixed>>
     */
    private function activity_evidence(
        array $users
    ): array {
        global $USER;

        if (!$users) {
            return [];
        }

        $userids =
            array_values(
                array_filter(
                    array_map(
                        static function(array $user): int {
                            return (int)($user['userid'] ?? 0);
                        },
                        $users
                    )
                )
            );

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

            $entry = [
                'cmid' =>
                    (int)$cm->id,

                'module' =>
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

                'users' =>
                    [],
            ];

            switch ($cm->modname) {
                case 'assign':
                    if (
                        has_capability(
                            'mod/assign:grade',
                            $context
                        )
                    ) {
                        $entry['users'] =
                            $this->assignment_user_evidence(
                                $cm,
                                $context,
                                $userids
                            );
                    }
                    break;

                case 'quiz':
                    if (
                        has_capability(
                            'mod/quiz:viewreports',
                            $context
                        ) ||
                        has_capability(
                            'mod/quiz:grade',
                            $context
                        )
                    ) {
                        $entry['users'] =
                            $this->quiz_user_evidence(
                                $cm,
                                $context,
                                $userids
                            );
                    }
                    break;

                default:
                    /*
                     * Universal completion/grade evidence for
                     * third-party modules is resolved separately.
                     *
                     * Never invent submission semantics for an
                     * unfamiliar plugin.
                     */
                    break;
            }

            if ($entry['users']) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Actual assignment submissions and recorded assignment grades.
     *
     * Uses Moodle's assignment tables but does not assume that
     * a submission row alone means "ready for grading".
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param int[] $userids
     * @return array<int, array<string, mixed>>
     */
    private function assignment_user_evidence(
        \cm_info $cm,
        \context_module $context,
        array $userids
    ): array {
        global $CFG, $DB;

        require_once(
            $CFG->dirroot .
            '/mod/assign/locallib.php'
        );

        if (!$userids) {
            return [];
        }

        [$insql, $params] =
            $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'assignuserid'
            );

        $params['assignmentid'] =
            (int)$cm->instance;

        $submissions =
            $DB->get_records_sql(
                "SELECT s.*
                   FROM {assign_submission} s
                  WHERE s.assignment = :assignmentid
                    AND s.userid {$insql}
               ORDER BY s.userid, s.attemptnumber DESC, s.id DESC",
                $params
            );

        $latest = [];

        foreach ($submissions as $submission) {
            $userid =
                (int)$submission->userid;

            if (!isset($latest[$userid])) {
                $latest[$userid] =
                    $submission;
            }
        }

        [$gradeinsql, $gradeparams] =
            $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'gradeuserid'
            );

        $gradeparams['assignmentid'] =
            (int)$cm->instance;

        $grades =
            $DB->get_records_sql(
                "SELECT g.*
                   FROM {assign_grades} g
                  WHERE g.assignment = :assignmentid
                    AND g.userid {$gradeinsql}",
                $gradeparams
            );

        $grademap = [];

        foreach ($grades as $grade) {
            $grademap[(int)$grade->userid] =
                $grade;
        }

        $result = [];

        foreach ($userids as $userid) {
            $submission =
                $latest[$userid]
                ?? null;

            $grade =
                $grademap[$userid]
                ?? null;

            if (
                !$submission &&
                !$grade
            ) {
                continue;
            }

            $result[] = [
                'userid' =>
                    $userid,

                'submission' =>
                    $submission
                        ? [
                            'submissionid' =>
                                (int)$submission->id,

                            'status' =>
                                (string)$submission->status,

                            'attemptnumber' =>
                                (int)$submission->attemptnumber,

                            'timecreated' =>
                                (int)$submission->timecreated,

                            'timemodified' =>
                                (int)$submission->timemodified,

                            'latest' =>
                                (int)($submission->latest ?? 0),

                            'groupid' =>
                                (int)($submission->groupid ?? 0),
                        ]
                        : null,

                'assignmentgrade' =>
                    $grade
                        ? [
                            'gradeid' =>
                                (int)$grade->id,

                            'grade' =>
                                $grade->grade !== null
                                    ? (float)$grade->grade
                                    : null,

                            'attemptnumber' =>
                                (int)$grade->attemptnumber,

                            'timemodified' =>
                                (int)$grade->timemodified,

                            'grader' =>
                                (int)($grade->grader ?? 0),
                        ]
                        : null,

                'gradingurl' =>
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

        return $result;
    }

    /**
     * Actual quiz attempts.
     *
     * Preview attempts are excluded from participant evidence.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param int[] $userids
     * @return array<int, array<string, mixed>>
     */
    private function quiz_user_evidence(
        \cm_info $cm,
        \context_module $context,
        array $userids
    ): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        [$insql, $params] =
            $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'quizuserid'
            );

        $params['quizid'] =
            (int)$cm->instance;

        $attempts =
            $DB->get_records_sql(
                "SELECT qa.*
                   FROM {quiz_attempts} qa
                  WHERE qa.quiz = :quizid
                    AND qa.userid {$insql}
                    AND qa.preview = 0
               ORDER BY qa.userid, qa.attempt DESC, qa.id DESC",
                $params
            );

        $result = [];

        foreach ($attempts as $attempt) {
            $userid =
                (int)$attempt->userid;

            $result[] = [
                'userid' =>
                    $userid,

                'attemptid' =>
                    (int)$attempt->id,

                'attemptnumber' =>
                    (int)$attempt->attempt,

                'state' =>
                    (string)$attempt->state,

                'timestart' =>
                    (int)$attempt->timestart,

                'timefinish' =>
                    (int)$attempt->timefinish,

                'timemodified' =>
                    (int)$attempt->timemodified,

                'sumgrades' =>
                    $attempt->sumgrades !== null
                        ? (float)$attempt->sumgrades
                        : null,

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

        return $result;
    }
}
