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
 * Read-only Moodle teacher work intelligence.
 *
 * This service never changes Moodle data.
 * It identifies visible course work which may need the
 * current teacher's attention.
 */
final class teacher_work {

    private \stdClass $course;
    private \context_course $coursecontext;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext = \context_course::instance($course->id);
    }

    /**
     * Build the current teacher's work queue.
     *
     * @return array<string, mixed>
     */
    public function build(): array {
        return [
            'forums' => $this->forum_work(),
        ];
    }

    /**
     * Find participant forum posts which do not currently have
     * a direct reply from the logged-in teacher.
     *
     * This is an attention signal, not a claim that every post
     * must receive a teacher reply.
     *
     * @return array<string, mixed>
     */
    private function forum_work(): array {
        global $DB, $USER;

        $modinfo = get_fast_modinfo($this->course, $USER->id);

        $items = [];
        $forumcount = 0;
        $postcount = 0;

        foreach ($modinfo->get_cms() as $cm) {
            if (
                $cm->modname !== 'forum' ||
                !$cm->uservisible
            ) {
                continue;
            }

            $context = \context_module::instance($cm->id);

            if (!has_capability('mod/forum:viewdiscussion', $context, $USER)) {
                continue;
            }

            $forum = $DB->get_record(
                'forum',
                ['id' => $cm->instance],
                'id,name',
                IGNORE_MISSING
            );

            if (!$forum) {
                continue;
            }

            $groupsql = '';
            $params = ['forumid' => (int)$forum->id];

            if (
                groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $context, $USER)
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

                [$insql, $inparams] = $DB->get_in_or_equal(
                    $groupids,
                    SQL_PARAMS_NAMED,
                    'teacherworkgroup'
                );

                $groupsql = " AND d.groupid {$insql}";
                $params = array_merge($params, $inparams);
            }

            $discussions = $DB->get_records_sql(
                "SELECT d.id, d.name
                   FROM {forum_discussions} d
                  WHERE d.forum = :forumid
                        {$groupsql}
               ORDER BY d.timemodified DESC",
                $params
            );

            $forumhaswork = false;

            foreach ($discussions as $discussion) {
                $posts = $DB->get_records(
                    'forum_posts',
                    ['discussion' => $discussion->id],
                    'created ASC',
                    'id,userid,parent,subject,created'
                );

                if (!$posts) {
                    continue;
                }

                /*
                 * Record which posts already have a direct reply
                 * from the current teacher.
                 */
                $repliedparents = [];

                foreach ($posts as $post) {
                    if (
                        (int)$post->userid === (int)$USER->id &&
                        (int)$post->parent > 0
                    ) {
                        $repliedparents[(int)$post->parent] = true;
                    }
                }

                foreach ($posts as $post) {
                    if ((int)$post->userid === (int)$USER->id) {
                        continue;
                    }

                    if ($this->is_teacher_user((int)$post->userid)) {
                        continue;
                    }

                    if (!empty($repliedparents[(int)$post->id])) {
                        continue;
                    }

                    $author = $DB->get_record(
                        'user',
                        ['id' => $post->userid],
                        'id,firstname,lastname',
                        IGNORE_MISSING
                    );

                    $authorname = $author
                        ? fullname($author)
                        : get_string('unknownuser');

                    $items[] = [
                        'cmid' => (int)$cm->id,
                        'forumid' => (int)$forum->id,
                        'forumname' => format_string(
                            $forum->name,
                            true,
                            ['context' => $context]
                        ),
                        'discussionid' => (int)$discussion->id,
                        'discussionname' => format_string(
                            $discussion->name,
                            true,
                            ['context' => $context]
                        ),
                        'postid' => (int)$post->id,
                        'author' => $authorname,
                        'subject' => format_string(
                            (string)$post->subject,
                            true,
                            ['context' => $context]
                        ),
                        'created' => (int)$post->created,
                        'url' => (
                            new \moodle_url(
                                '/mod/forum/discuss.php',
                                ['d' => $discussion->id],
                                'p' . $post->id
                            )
                        )->out(false),
                    ];

                    $postcount++;
                    $forumhaswork = true;
                }
            }

            if ($forumhaswork) {
                $forumcount++;
            }
        }

        return [
            'postswithoutmydirectreply' => $postcount,
            'forumswithattention' => $forumcount,
            'items' => $items,
        ];
    }

    /**
     * Determine whether another user is acting as teaching staff
     * in this course.
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
