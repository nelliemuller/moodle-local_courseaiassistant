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
 * Read-only Moodle forum evidence.
 *
 * Builds factual, permission-aware evidence about:
 * discussions, posts, replies, reply authors, teacher responses,
 * post ratings, rating authors, whole-forum grades, completion,
 * and forum grading configuration.
 */
final class forum_evidence {

    private \stdClass $course;
    private \context_course $coursecontext;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext = \context_course::instance($course->id);
    }

    /**
     * Build evidence for one forum activity.
     *
     * @param \cm_info $cm
     * @return array<string, mixed>|null
     */
    public function build_for_cm(\cm_info $cm): ?array {
        global $DB, $USER;

        if ($cm->modname !== 'forum') {
            return null;
        }

        $context = \context_module::instance($cm->id);

        if (!has_capability('mod/forum:viewdiscussion', $context, $USER)) {
            return null;
        }

        $forum = $DB->get_record(
            'forum',
            ['id' => $cm->instance],
            '*',
            IGNORE_MISSING
        );

        if (!$forum) {
            return null;
        }

        $gradingmode = $this->grading_mode($forum);

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

            [$insql, $inparams] = $DB->get_in_or_equal(
                $groupids,
                SQL_PARAMS_NAMED,
                'forumevidencegroup'
            );

            $groupsql = " AND d.groupid {$insql}";
            $params = array_merge($params, $inparams);
        }

        $discussions = $DB->get_records_sql(
            "SELECT d.*
               FROM {forum_discussions} d
              WHERE d.forum = :forumid
                    {$groupsql}
           ORDER BY d.timemodified DESC",
            $params
        );

        $discussionevidence = [];
        $participantevidence = [];

        foreach ($discussions as $discussion) {
            $posts = $DB->get_records(
                'forum_posts',
                ['discussion' => $discussion->id],
                'created ASC'
            );

            if (!$posts) {
                continue;
            }

            $postsbyid = [];

            foreach ($posts as $post) {
                $postsbyid[(int)$post->id] = $post;
            }

            $discussionposts = [];

            foreach ($posts as $post) {
                $userid = (int)$post->userid;

                if ($userid <= 0) {
                    continue;
                }

                $author = $DB->get_record(
                    'user',
                    ['id' => $userid],
                    'id,firstname,lastname',
                    IGNORE_MISSING
                );

                $authorname = $author
                    ? fullname($author)
                    : get_string('unknownuser');

                $replies = [];

                foreach ($posts as $candidate) {
                    if ((int)$candidate->parent !== (int)$post->id) {
                        continue;
                    }

                    $replyauthor = $DB->get_record(
                        'user',
                        ['id' => $candidate->userid],
                        'id,firstname,lastname',
                        IGNORE_MISSING
                    );

                    $replies[] = [
                        'postid' => (int)$candidate->id,
                        'userid' => (int)$candidate->userid,
                        'author' => $replyauthor
                            ? fullname($replyauthor)
                            : get_string('unknownuser'),
                        'isteacher' =>
                            $this->is_teacher_user((int)$candidate->userid),
                        'created' => (int)$candidate->created,
                        'subject' => format_string(
                            (string)$candidate->subject,
                            true,
                            ['context' => $context]
                        ),
                    ];
                }

                $ratings = $DB->get_records(
                    'rating',
                    [
                        'contextid' => (int)$context->id,
                        'component' => 'mod_forum',
                        'ratingarea' => 'post',
                        'itemid' => (int)$post->id,
                    ],
                    'timecreated ASC'
                );

                $ratingevidence = [];

                foreach ($ratings as $rating) {
                    $rater = $DB->get_record(
                        'user',
                        ['id' => $rating->userid],
                        'id,firstname,lastname',
                        IGNORE_MISSING
                    );

                    $ratingevidence[] = [
                        'ratingid' => (int)$rating->id,
                        'userid' => (int)$rating->userid,
                        'rater' => $rater
                            ? fullname($rater)
                            : get_string('unknownuser'),
                        'isteacher' =>
                            $this->is_teacher_user((int)$rating->userid),
                        'rating' => $rating->rating,
                        'scaleid' => (int)$rating->scaleid,
                        'timecreated' => (int)$rating->timecreated,
                        'timemodified' => (int)$rating->timemodified,
                    ];
                }

                $myresponse = false;
                $teacherresponses = [];

                foreach ($replies as $reply) {
                    if (!empty($reply['isteacher'])) {
                        $teacherresponses[] = $reply;

                        if (
                            (int)$reply['userid'] === (int)$USER->id
                        ) {
                            $myresponse = true;
                        }
                    }
                }

                /*
                 * Also count a later teacher post in the same discussion
                 * as a response in the discussion, even when it was not
                 * directly parented to this specific post.
                 */
                if (!$myresponse) {
                    foreach ($posts as $candidate) {
                        if (
                            (int)$candidate->userid === (int)$USER->id &&
                            (int)$candidate->created >= (int)$post->created
                        ) {
                            $myresponse = true;
                            break;
                        }
                    }
                }

                $postentry = [
                    'postid' => (int)$post->id,
                    'parentid' => (int)$post->parent,
                    'userid' => $userid,
                    'author' => $authorname,
                    'isteacher' => $this->is_teacher_user($userid),
                    'created' => (int)$post->created,
                    'modified' => (int)$post->modified,
                    'subject' => format_string(
                        (string)$post->subject,
                        true,
                        ['context' => $context]
                    ),

                    /*
                     * Actual Moodle post content.
                     *
                     * The assistant needs the participant's real words
                     * to understand questions, suggestions, quotations,
                     * requests, reflections and conversational context.
                     */
                    'message' => trim(
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

                    'replies' => $replies,
                    'replycount' => count($replies),
                    'teacherresponses' => $teacherresponses,
                    'hasmyresponse' => $myresponse,
                    'ratings' => $ratingevidence,
                    'ratingcount' => count($ratingevidence),
                    'hasteacherrating' => $this->has_teacher_rating(
                        $ratingevidence
                    ),
                    'url' => (
                        new \moodle_url(
                            '/mod/forum/discuss.php',
                            ['d' => $discussion->id],
                            'p' . $post->id
                        )
                    )->out(false),
                ];

                $discussionposts[] = $postentry;

                if (!$this->is_teacher_user($userid)) {
                    if (!isset($participantevidence[$userid])) {
                        $participantevidence[$userid] = [
                            'userid' => $userid,
                            'name' => $authorname,
                            'posts' => 0,
                            'repliesreceived' => 0,
                            'teacherresponses' => 0,
                            'ratingsreceived' => 0,
                            'teacherratingsreceived' => 0,
                            'forumgrade' => null,
                        ];
                    }

                    $participantevidence[$userid]['posts']++;

                    $participantevidence[$userid]['repliesreceived'] +=
                        count($replies);

                    $participantevidence[$userid]['teacherresponses'] +=
                        count($teacherresponses);

                    $participantevidence[$userid]['ratingsreceived'] +=
                        count($ratingevidence);

                    foreach ($ratingevidence as $ratingentry) {
                        if (!empty($ratingentry['isteacher'])) {
                            $participantevidence[$userid]
                                ['teacherratingsreceived']++;
                        }
                    }
                }
            }

            $discussionevidence[] = [
                'discussionid' => (int)$discussion->id,
                'name' => format_string(
                    (string)$discussion->name,
                    true,
                    ['context' => $context]
                ),
                'userid' => (int)$discussion->userid,
                'groupid' => (int)$discussion->groupid,
                'posts' => $discussionposts,
                'url' => (
                    new \moodle_url(
                        '/mod/forum/discuss.php',
                        ['d' => $discussion->id]
                    )
                )->out(false),
            ];
        }

        /*
         * Whole-forum or rating-derived gradebook evidence.
         */
        foreach ($participantevidence as $userid => &$participant) {
            $participant['forumgrade'] =
                $this->participant_grade(
                    $cm,
                    (int)$userid
                );
        }
        unset($participant);

        return [
            'cmid' => (int)$cm->id,
            'forumid' => (int)$forum->id,
            'name' => format_string(
                (string)$forum->name,
                true,
                ['context' => $context]
            ),
            'grading' => [
                'mode' => $gradingmode,
                'assessed' => (int)$forum->assessed,
                'scale' => (int)$forum->scale,
                'grade_forum' => (int)$forum->grade_forum,
                'assesstimestart' => (int)$forum->assesstimestart,
                'assesstimefinish' => (int)$forum->assesstimefinish,
            ],
            'completion' => [
                'discussionsrequired' =>
                    (int)$forum->completiondiscussions,
                'repliesrequired' =>
                    (int)$forum->completionreplies,
                'postsrequired' =>
                    (int)$forum->completionposts,
            ],
            'participants' =>
                array_values($participantevidence),
            'discussions' => $discussionevidence,
        ];
    }

    /**
     * Determine actual forum grading mode.
     */
    private function grading_mode(\stdClass $forum): string {
        if ((int)$forum->grade_forum !== 0) {
            return 'whole_forum';
        }

        if ((int)$forum->assessed !== 0) {
            return 'post_ratings';
        }

        return 'none';
    }

    /**
     * Whether any rating came from teaching staff.
     */
    private function has_teacher_rating(array $ratings): bool {
        foreach ($ratings as $rating) {
            if (!empty($rating['isteacher'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read gradebook state for one participant in one forum.
     */
    private function participant_grade(
        \cm_info $cm,
        int $userid
    ): ?array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/querylib.php');

        $items =
            \grade_get_grade_items_for_activity(
                $cm,
                true
            );

        if (!$items) {
            return null;
        }

        $grades =
            \grade_get_grades(
                $this->course->id,
                'mod',
                'forum',
                $cm->instance,
                $userid
            );

        if (
            empty($grades->items) ||
            empty($grades->items[0])
        ) {
            return null;
        }

        $item = $grades->items[0];
        $grade = $item->grades[$userid] ?? null;

        return [
            'gradeitemid' =>
                isset($item->id) ? (int)$item->id : 0,
            'gradepass' =>
                isset($item->gradepass)
                    ? $item->gradepass
                    : null,
            'grade' =>
                $grade->grade ?? null,
            'rawgrade' =>
                $grade->rawgrade ?? null,
            'feedback' =>
                $grade->feedback ?? null,
            'hidden' =>
                !empty($grade->hidden),
            'locked' =>
                !empty($grade->locked),
        ];
    }

    /**
     * Whether a user has teaching capabilities in this course.
     */
    private function is_teacher_user(int $userid): bool {
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
