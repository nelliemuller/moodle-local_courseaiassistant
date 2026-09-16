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
 * Deep permission-aware Quiz attempt/question evidence.
 *
 * Uses Moodle Quiz and Question APIs.
 *
 * Read only:
 * - never grades
 * - never regrades
 * - never modifies attempts
 *
 * @package local_courseaiassistant
 */
final class quiz_question_evidence_resolver {

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
     * Resolve quiz detail only for relevant requests.
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
            !$this->request_needs_quiz_detail(
                $question,
                $history
            )
        ) {
            return [
                'active' => false,
                'quizzes' => [],
            ];
        }

        if (!$this->can_inspect_quizzes()) {
            return [
                'active' => false,
                'reason' => 'insufficient_permission',
                'quizzes' => [],
            ];
        }

        global $CFG, $USER;

        require_once(
            $CFG->dirroot .
            '/mod/quiz/locallib.php'
        );

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $quizzes = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (
                !$cm instanceof \cm_info ||
                $cm->modname !== 'quiz'
            ) {
                continue;
            }

            $context =
                \context_module::instance(
                    (int)$cm->id
                );

            if (
                !$cm->uservisible &&
                !has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                )
            ) {
                continue;
            }

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
                continue;
            }

            $resolved =
                $this->quiz_evidence(
                    $cm,
                    $context,
                    $question
                );

            if ($resolved !== null) {
                $quizzes[] = $resolved;
            }
        }

        return [
            'active' => true,
            'quizzes' => $quizzes,
        ];
    }

    /**
     * Determine whether deep Quiz evidence is needed.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return bool
     */
    private function request_needs_quiz_detail(
        string $question,
        array $history
    ): bool {
        $text =
            \core_text::strtolower(
                trim(
                    $question . ' ' .
                    $this->history_text(
                        $history
                    )
                )
            );

        if ($text === '') {
            return false;
        }

        return (bool)preg_match(
            '/\b(' .
            'quiz|quizzes|' .
            'question|questions|' .
            'attempt|attempts|' .
            'response|responses|answer|answers|' .
            'manual grading|manually grade|needs grading|' .
            'grade quiz|grade question|' .
            'review quiz|review attempt|' .
            'what did .* answer|' .
            'which question|' .
            'essay question' .
            ')\b/u',
            $text
        );
    }

    /**
     * Recent conversation text.
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
                    $parts[] = $entry[$key];
                }
            }
        }

        return implode(
            ' ',
            $parts
        );
    }

    /**
     * Course-level authority.
     *
     * @return bool
     */
    private function can_inspect_quizzes(): bool {
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
     * Resolve one Quiz.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param string $question
     * @return array<string, mixed>|null
     */
    private function quiz_evidence(
        \cm_info $cm,
        \context_module $context,
        string $question
    ): ?array {
        global $DB;

        $quiz =
            $DB->get_record(
                'quiz',
                [
                    'id' =>
                        (int)$cm->instance,
                ],
                '*',
                IGNORE_MISSING
            );

        if (!$quiz) {
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

        $userids = [];
        foreach ($attempts as $attempt) {
            $userid =
                (int)$attempt->userid;

            if ($userid > 0) {
                $userids[$userid] =
                    $userid;
            }
        }

        $quizusers = [];
        if ($userids) {
            $quizusers =
                $DB->get_records_list(
                    'user',
                    'id',
                    array_values($userids),
                    '',
                    'id,firstname,lastname'
                );
        }

        $questionlower =
            \core_text::strtolower(
                $question
            );

        /*
         * If a named participant appears in the question,
         * narrow attempts to that user.
         */
        $nameduserid =
            $this->named_userid(
                $quizusers,
                $questionlower
            );

        $evidence = [];

        foreach ($attempts as $attemptrecord) {
            $userid =
                (int)$attemptrecord->userid;

            if (
                $nameduserid > 0 &&
                $userid !== $nameduserid
            ) {
                continue;
            }

            $user =
                $quizusers[$userid]
                ?? null;

            if (!$user) {
                continue;
            }

            try {
                $attempt =
                    \mod_quiz\quiz_attempt::create(
                        (int)$attemptrecord->id
                    );
            } catch (\Throwable $e) {
                continue;
            }

            /*
             * Do not expose attempt evidence if the current Moodle
             * user does not have review/report authority.
             */
            try {
                $attempt->require_capability(
                    'mod/quiz:viewreports'
                );
            } catch (\Throwable $e) {
                if (
                    !has_capability(
                        'mod/quiz:grade',
                        $context
                    )
                ) {
                    continue;
                }
            }

            $requiresmanual =
                false;

            try {
                $requiresmanual =
                    (bool)$attempt->requires_manual_grading();
            } catch (\Throwable $e) {
                $requiresmanual = false;
            }

            $slots =
                [];

            try {
                $slots =
                    $attempt->get_slots(
                        'all'
                    );
            } catch (\Throwable $e) {
                $slots = [];
            }

            $questions =
                [];

            foreach ($slots as $slot) {
                $slot =
                    (int)$slot;

                if ($slot <= 0) {
                    continue;
                }

                $questions[] =
                    $this->question_evidence(
                        $attempt,
                        $slot
                    );
            }

            $reviewurl = '';

            try {
                $url =
                    $attempt->review_url(
                        null,
                        -1,
                        true
                    );

                if ($url instanceof \moodle_url) {
                    $reviewurl =
                        $url->out(false);
                }
            } catch (\Throwable $e) {
                $reviewurl =
                    (
                        new \moodle_url(
                            '/mod/quiz/review.php',
                            [
                                'attempt' =>
                                    (int)$attemptrecord->id,
                            ]
                        )
                    )->out(false);
            }

            $evidence[] = [
                'userid' =>
                    $userid,

                'participant' =>
                    fullname($user),

                'attemptid' =>
                    (int)$attemptrecord->id,

                'attemptnumber' =>
                    (int)$attemptrecord->attempt,

                'state' =>
                    (string)$attemptrecord->state,

                'timestart' =>
                    (int)$attemptrecord->timestart,

                'timefinish' =>
                    (int)$attemptrecord->timefinish,

                'sumgrades' =>
                    $attemptrecord->sumgrades !== null
                        ? (float)$attemptrecord->sumgrades
                        : null,

                /*
                 * This is the authoritative Quiz API signal that at
                 * least one question still requires manual grading.
                 */
                'requiresmanualgrading' =>
                    $requiresmanual,

                'questions' =>
                    $questions,

                'reviewurl' =>
                    $reviewurl,
            ];
        }

        return [
            'cmid' =>
                (int)$cm->id,

            'quizid' =>
                (int)$cm->instance,

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

            'attempts' =>
                $evidence,
        ];
    }

    /**
     * Resolve one question attempt.
     *
     * @param \mod_quiz\quiz_attempt $attempt
     * @param int $slot
     * @return array<string, mixed>
     */
    private function question_evidence(
        \mod_quiz\quiz_attempt $attempt,
        int $slot
    ): array {
        $qa = null;

        try {
            $qa =
                $attempt->get_question_attempt(
                    $slot
                );
        } catch (\Throwable $e) {
            $qa = null;
        }

        if (!$qa instanceof \question_attempt) {
            return [
                'slot' =>
                    $slot,

                'available' =>
                    false,
            ];
        }

        $questionname = '';

        try {
            $questionname =
                (string)$attempt->get_question_name(
                    $slot
                );
        } catch (\Throwable $e) {
            $questionname = '';
        }

        $questiontype = '';

        try {
            $questiontype =
                (string)$attempt->get_question_type_name(
                    $slot
                );
        } catch (\Throwable $e) {
            $questiontype = '';
        }

        $questionsummary = '';

        try {
            $questionsummary =
                trim(
                    (string)$qa->get_question_summary()
                );
        } catch (\Throwable $e) {
            $questionsummary = '';
        }

        $responsesummary = '';

        try {
            $responsesummary =
                trim(
                    (string)$qa->get_response_summary()
                );
        } catch (\Throwable $e) {
            $responsesummary = '';
        }

        $statestring = '';

        try {
            $statestring =
                (string)$qa->get_state_string(
                    true
                );
        } catch (\Throwable $e) {
            $statestring = '';
        }

        $mark = null;

        try {
            $fraction =
                $qa->get_fraction();

            if ($fraction !== null) {
                $mark =
                    (float)$fraction;
            }
        } catch (\Throwable $e) {
            $mark = null;
        }

        $manualcomment = '';

        try {
            if ($qa->has_manual_comment()) {
                $commentdata =
                    $qa->get_current_manual_comment();

                if (
                    is_array($commentdata) &&
                    isset($commentdata['comment'])
                ) {
                    $manualcomment =
                        trim(
                            html_to_text(
                                (string)$commentdata['comment'],
                                0,
                                false
                            )
                        );
                }
            }
        } catch (\Throwable $e) {
            $manualcomment = '';
        }

        $questionreviewurl = '';

        try {
            $url =
                $attempt->review_url(
                    $slot
                );

            if ($url instanceof \moodle_url) {
                $questionreviewurl =
                    $url->out(false);
            }
        } catch (\Throwable $e) {
            $questionreviewurl = '';
        }

        return [
            'slot' =>
                $slot,

            'name' =>
                $questionname,

            'type' =>
                $questiontype,

            /*
             * Plain textual summaries supplied by Moodle's
             * Question API.
             */
            'questionsummary' =>
                $this->limit_text(
                    $questionsummary
                ),

            'responsesummary' =>
                $this->limit_text(
                    $responsesummary
                ),

            'state' =>
                $statestring,

            /*
             * Fraction is question-level mark evidence.
             * It is not the overall Quiz grade.
             */
            'fraction' =>
                $mark,

            'manualcomment' =>
                $this->limit_text(
                    $manualcomment
                ),

            'reviewurl' =>
                $questionreviewurl,
        ];
    }

    /**
     * Narrow by exact participant full name when possible.
     *
     * @param array<int, \stdClass> $users
     * @param string $questionlower
     * @return int
     */
    private function named_userid(
        array $users,
        string $questionlower
    ): int {
        foreach ($users as $userid => $user) {
            $userid =
                (int)$userid;

            $fullname =
                \core_text::strtolower(
                    fullname($user)
                );

            if (
                $fullname !== '' &&
                \core_text::strpos(
                    $questionlower,
                    $fullname
                ) !== false
            ) {
                return $userid;
            }
        }

        return 0;
    }

    /**
     * Limit AI context size.
     *
     * @param string $text
     * @return string
     */
    private function limit_text(
        string $text
    ): string {
        if (
            \core_text::strlen(
                $text
            ) <= 20000
        ) {
            return $text;
        }

        return
            \core_text::substr(
                $text,
                0,
                20000
            );
    }
}
