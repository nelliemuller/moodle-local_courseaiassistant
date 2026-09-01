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
 * Permission-aware forum pedagogical workflow evidence.
 *
 * This resolver does NOT replace:
 *
 * - forum_evidence
 * - grading_evidence
 * - teacher_work
 * - replytargets
 * - gradingtargets
 *
 * Its purpose is different:
 *
 * distinguish raw Moodle grade state from pedagogical work state.
 *
 * Example:
 *
 * participant contributes
 * teacher replies requesting additional work
 * participant has not yet responded
 * no grade exists
 *
 * That is structurally "ungraded", but it may be
 * "waiting for participant revision" rather than
 * "ready for teacher grading".
 *
 * Semantic interpretation remains the AI reasoning layer's job.
 *
 * Read only.
 *
 * @package local_courseaiassistant
 */
final class forum_work_state_resolver {

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
     * Resolve forum workflow only when the current request needs it.
     *
     * Recent conversation is included so a follow-up such as
     * "Did you check that?" can continue a grading/revision task.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return array<string, mixed>
     */
    public function resolve(
        string $question,
        array $history = []
    ): array {
        if (!$this->request_needs_work_state(
            $question,
            $history
        )) {
            return [
                'active' => false,
                'forums' => [],
            ];
        }

        if (!$this->can_inspect_work()) {
            return [
                'active' => false,
                'reason' => 'insufficient_permission',
                'forums' => [],
            ];
        }

        global $USER;

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $forums = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (
                !$cm instanceof \cm_info ||
                $cm->modname !== 'forum'
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
                    'mod/forum:viewdiscussion',
                    $context
                )
            ) {
                continue;
            }

            $forumstate =
                $this->forum_state(
                    $cm,
                    $context
                );

            if ($forumstate !== null) {
                $forums[] = $forumstate;
            }
        }

        return [
            'active' => true,
            'forums' => $forums,
        ];
    }

    /**
     * Determine whether this question needs workflow interpretation.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return bool
     */
    private function request_needs_work_state(
        string $question,
        array $history
    ): bool {
        $text =
            \core_text::strtolower(
                trim(
                    $question . ' ' .
                    $this->recent_history_text($history)
                )
            );

        if ($text === '') {
            return false;
        }

        return (bool)preg_match(
            '/\b(' .
            'grade|grades|graded|grading|ungraded|' .
            'ready for grading|needs grading|need grading|' .
            'review|reviewing|feedback|' .
            'revise|revision|redo|resubmit|resubmission|' .
            'still need|still needs|more work|' .
            'follow instructions|instructions|' .
            'incomplete|not complete|' .
            'waiting for participant|waiting for student|' .
            'did you check|have you checked' .
            ')\b/u',
            $text
        );
    }

    /**
     * Compact recent history text.
     *
     * @param array<int, mixed> $history
     * @return string
     */
    private function recent_history_text(
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
     * Verify teacher/manager/admin authority.
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
     * Build workflow evidence for one forum.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>|null
     */
    private function forum_state(
        \cm_info $cm,
        \context_module $context
    ): ?array {
        global $DB;

        $forum =
            $DB->get_record(
                'forum',
                [
                    'id' =>
                        (int)$cm->instance,
                ],
                '*',
                IGNORE_MISSING
            );

        if (!$forum) {
            return null;
        }

        /*
         * Existing forum_evidence remains the authoritative source
         * for actual posts, replies, ratings, teacher responses and
         * gradebook evidence.
         */
        $evidence =
            (
                new forum_evidence(
                    $this->course
                )
            )->build_for_cm(
                $cm
            );

        if (!$evidence) {
            return null;
        }

        $gradingmode =
            (string)(
                $evidence['grading']['mode']
                ?? 'none'
            );

        /*
         * A forum with no Moodle grading mechanism must never be
         * turned into a grading obligation.
         */
        if ($gradingmode === 'none') {
            return [
                'cmid' =>
                    (int)$cm->id,

                'forumid' =>
                    (int)$cm->instance,

                'name' =>
                    format_string(
                        (string)$cm->name,
                        true,
                        ['context' => $context]
                    ),

                'gradingmode' =>
                    'none',

                'gradeable' =>
                    false,

                'instructions' =>
                    $this->plain_text(
                        (string)($forum->intro ?? ''),
                        (int)($forum->introformat ?? FORMAT_HTML),
                        $context
                    ),

                'participants' =>
                    [],
            ];
        }

        $participantmap = [];

        foreach (
            (
                $evidence['participants']
                ?? []
            ) as $participant
        ) {
            if (!is_array($participant)) {
                continue;
            }

            $userid =
                (int)(
                    $participant['userid']
                    ?? 0
                );

            if ($userid <= 0) {
                continue;
            }

            $participantmap[$userid] =
                $participant;
        }

        $work = [];

        foreach (
            (
                $evidence['discussions']
                ?? []
            ) as $discussion
        ) {
            if (!is_array($discussion)) {
                continue;
            }

            $discussionid =
                (int)(
                    $discussion['discussionid']
                    ?? 0
                );

            foreach (
                (
                    $discussion['posts']
                    ?? []
                ) as $post
            ) {
                if (
                    !is_array($post) ||
                    !empty($post['isteacher'])
                ) {
                    continue;
                }

                $userid =
                    (int)(
                        $post['userid']
                        ?? 0
                    );

                if ($userid <= 0) {
                    continue;
                }

                $participant =
                    $participantmap[$userid]
                    ?? [];

                $teacherresponses =
                    $this->teacher_response_details(
                        $post['teacherresponses']
                        ?? [],
                        $discussionid
                    );

                $latestteacherresponse =
                    $this->latest_response(
                        $teacherresponses
                    );

                $participantafterteacher =
                    $this->participant_response_after(
                        $post,
                        $userid,
                        $latestteacherresponse
                    );

                $gradestate =
                    $this->grade_state(
                        $gradingmode,
                        $post,
                        $participant
                    );

                $postid =
                    (int)(
                        $post['postid']
                        ?? 0
                    );

                $work[] = [
                    'userid' =>
                        $userid,

                    'participant' =>
                        (string)(
                            $post['author']
                            ?? (
                                $participant['name']
                                ?? ''
                            )
                        ),

                    'discussionid' =>
                        $discussionid,

                    'discussion' =>
                        (string)(
                            $discussion['name']
                            ?? ''
                        ),

                    'postid' =>
                        $postid,

                    'subject' =>
                        (string)(
                            $post['subject']
                            ?? ''
                        ),

                    'participantmessage' =>
                        (string)(
                            $post['message']
                            ?? ''
                        ),

                    'participantcreated' =>
                        (int)(
                            $post['created']
                            ?? 0
                        ),

                    'posturl' =>
                        $postid > 0 &&
                        $discussionid > 0
                            ? (
                                new \moodle_url(
                                    '/mod/forum/discuss.php',
                                    [
                                        'd' =>
                                            $discussionid,
                                    ],
                                    'p' . $postid
                                )
                            )->out(false)
                            : '',

                    /*
                     * Exact teacher responses attached to this
                     * participant contribution.
                     */
                    'teacherresponses' =>
                        $teacherresponses,

                    'latestteacherresponse' =>
                        $latestteacherresponse,

                    /*
                     * Structural workflow signals.
                     *
                     * IMPORTANT:
                     * teacherresponded does not automatically mean
                     * revision_requested.
                     *
                     * The AI must read the actual feedback.
                     */
                    'teacherresponded' =>
                        !empty($teacherresponses),

                    'participantrespondedafterteacher' =>
                        $participantafterteacher,

                    'teacherfeedbackawaitingparticipant' =>
                        !empty($latestteacherresponse) &&
                        !$participantafterteacher,

                    /*
                     * Actual Moodle grade/rating evidence.
                     */
                    'gradestate' =>
                        $gradestate,

                    /*
                     * This flag tells the reasoning layer that raw
                     * "ungraded" evidence is insufficient by itself
                     * when teacher feedback followed the work.
                     */
                    'requiresworkflowinterpretation' =>
                        !empty($latestteacherresponse) &&
                        !empty(
                            $gradestate['ungraded']
                        ),
                ];
            }
        }

        return [
            'cmid' =>
                (int)$cm->id,

            'forumid' =>
                (int)$cm->instance,

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

            'gradingmode' =>
                $gradingmode,

            'gradeable' =>
                true,

            'instructions' =>
                $this->plain_text(
                    (string)($forum->intro ?? ''),
                    (int)($forum->introformat ?? FORMAT_HTML),
                    $context
                ),

            'completionrequirements' => [
                'discussions' =>
                    (int)(
                        $forum->completiondiscussions
                        ?? 0
                    ),

                'replies' =>
                    (int)(
                        $forum->completionreplies
                        ?? 0
                    ),

                'posts' =>
                    (int)(
                        $forum->completionposts
                        ?? 0
                    ),
            ],

            'work' =>
                $work,
        ];
    }

    /**
     * Expand teacher response metadata with the actual Moodle post text.
     *
     * @param array<int, mixed> $responses
     * @param int $discussionid
     * @return array<int, array<string, mixed>>
     */
    private function teacher_response_details(
        array $responses,
        int $discussionid
    ): array {
        global $DB;

        $result = [];

        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }

            $postid =
                (int)(
                    $response['postid']
                    ?? 0
                );

            if ($postid <= 0) {
                continue;
            }

            $record =
                $DB->get_record(
                    'forum_posts',
                    [
                        'id' =>
                            $postid,
                    ],
                    'id,discussion,userid,subject,message,messageformat,created,modified,parent',
                    IGNORE_MISSING
                );

            if (!$record) {
                continue;
            }

            /*
             * Do not silently attach a post from another discussion.
             */
            if (
                $discussionid > 0 &&
                (int)$record->discussion !==
                    $discussionid
            ) {
                continue;
            }

            $context =
                \context_course::instance(
                    (int)$this->course->id
                );

            $result[] = [
                'postid' =>
                    (int)$record->id,

                'userid' =>
                    (int)$record->userid,

                'author' =>
                    (string)(
                        $response['author']
                        ?? ''
                    ),

                'created' =>
                    (int)$record->created,

                'modified' =>
                    (int)$record->modified,

                'subject' =>
                    format_string(
                        (string)$record->subject,
                        true,
                        ['context' => $context]
                    ),

                'message' =>
                    $this->plain_text(
                        (string)$record->message,
                        (int)$record->messageformat,
                        $context
                    ),

                'url' =>
                    (
                        new \moodle_url(
                            '/mod/forum/discuss.php',
                            [
                                'd' =>
                                    (int)$record->discussion,
                            ],
                            'p' . (int)$record->id
                        )
                    )->out(false),
            ];
        }

        usort(
            $result,
            static function(
                array $a,
                array $b
            ): int {
                return
                    ((int)$a['created'])
                    <=>
                    ((int)$b['created']);
            }
        );

        return $result;
    }

    /**
     * Latest teacher response.
     *
     * @param array<int, array<string, mixed>> $responses
     * @return array<string, mixed>|null
     */
    private function latest_response(
        array $responses
    ): ?array {
        if (!$responses) {
            return null;
        }

        return
            $responses[
                count($responses) - 1
            ];
    }

    /**
     * Did the same participant contribute again after teacher feedback?
     *
     * @param array<string, mixed> $post
     * @param int $userid
     * @param array<string, mixed>|null $teacherresponse
     * @return bool
     */
    private function participant_response_after(
        array $post,
        int $userid,
        ?array $teacherresponse
    ): bool {
        if (!$teacherresponse) {
            return false;
        }

        $teachertime =
            (int)(
                $teacherresponse['created']
                ?? 0
            );

        foreach (
            (
                $post['replies']
                ?? []
            ) as $reply
        ) {
            if (
                !is_array($reply) ||
                (int)($reply['userid'] ?? 0)
                    !== $userid
            ) {
                continue;
            }

            if (
                (int)($reply['created'] ?? 0)
                    > $teachertime
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve raw Moodle grade/rating state.
     *
     * This deliberately does NOT claim pedagogical readiness.
     *
     * @param string $gradingmode
     * @param array<string, mixed> $post
     * @param array<string, mixed> $participant
     * @return array<string, mixed>
     */
    private function grade_state(
        string $gradingmode,
        array $post,
        array $participant
    ): array {
        if ($gradingmode === 'post_ratings') {
            $hasteacherrating =
                !empty(
                    $post['hasteacherrating']
                );

            return [
                'mode' =>
                    'post_ratings',

                'hasrecordedassessment' =>
                    $hasteacherrating,

                'ungraded' =>
                    !$hasteacherrating,
            ];
        }

        $forumgrade =
            is_array(
                $participant['forumgrade']
                ?? null
            )
                ? $participant['forumgrade']
                : [];

        $grade =
            $forumgrade['grade']
            ?? null;

        $rawgrade =
            $forumgrade['rawgrade']
            ?? null;

        $hasgrade =
            (
                $rawgrade !== null &&
                $rawgrade !== ''
            ) ||
            (
                $grade !== null &&
                $grade !== '' &&
                $grade !== '-'
            );

        return [
            'mode' =>
                'whole_forum',

            'hasrecordedassessment' =>
                $hasgrade,

            'grade' =>
                $grade,

            'rawgrade' =>
                $rawgrade,

            'feedback' =>
                $forumgrade['feedback']
                ?? null,

            'ungraded' =>
                !$hasgrade,
        ];
    }

    /**
     * Safe Moodle text conversion.
     *
     * @param string $value
     * @param int $format
     * @param \context $context
     * @return string
     */
    private function plain_text(
        string $value,
        int $format,
        \context $context
    ): string {
        if (trim($value) === '') {
            return '';
        }

        return trim(
            html_to_text(
                format_text(
                    $value,
                    $format,
                    [
                        'context' =>
                            $context,

                        'para' =>
                            false,

                        'filter' =>
                            true,
                    ]
                ),
                0,
                false
            )
        );
    }
}
