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

namespace local_courseaiassistant\module_adapter;

defined('MOODLE_INTERNAL') || die();

/**
 * Forum specialist evidence.
 *
 * Existing forum post/reply/grading engines remain authoritative.
 * This adapter exposes forum configuration and structural facts.
 *
 * @package local_courseaiassistant
 */
final class forum_adapter implements adapter_interface {

    public function modname(): string {
        return 'forum';
    }

    public function resolve(
        \cm_info $cm,
        \context_module $context,
        \stdClass $course
    ): array {
        global $DB;

        if (
            !has_capability(
                'mod/forum:viewdiscussion',
                $context
            )
        ) {
            return [];
        }

        $forum =
            $DB->get_record(
                'forum',
                ['id' => (int)$cm->instance],
                '*',
                IGNORE_MISSING
            );

        if (!$forum) {
            return [];
        }

        return [
            'adapter' =>
                'forum',

            'forumtype' =>
                (string)($forum->type ?? ''),

            'forcesubscribe' =>
                (int)($forum->forcesubscribe ?? 0),

            'trackingtype' =>
                (int)($forum->trackingtype ?? 0),

            'maxattachments' =>
                (int)($forum->maxattachments ?? 0),

            'maxbytes' =>
                (int)($forum->maxbytes ?? 0),

            'assessed' =>
                (int)($forum->assessed ?? 0),

            'scale' =>
                (int)($forum->scale ?? 0),

            'gradeforum' =>
                (int)($forum->grade_forum ?? 0),

            'completiondiscussions' =>
                (int)($forum->completiondiscussions ?? 0),

            'completionreplies' =>
                (int)($forum->completionreplies ?? 0),

            'completionposts' =>
                (int)($forum->completionposts ?? 0),

            'capabilities' => [
                'viewdiscussion' =>
                    has_capability(
                        'mod/forum:viewdiscussion',
                        $context
                    ),

                'replypost' =>
                    has_capability(
                        'mod/forum:replypost',
                        $context
                    ),

                'rate' =>
                    has_capability(
                        'mod/forum:rate',
                        $context
                    ),

                'grade' =>
                    has_capability(
                        'mod/forum:grade',
                        $context
                    ),
            ],

            /*
             * IMPORTANT:
             *
             * Discussions/posts/replies and teacher-work targets
             * continue to come from course_content_reader,
             * grading_evidence and existing forum intelligence.
             */
            'authoritativechildren' => [
                'course_content_reader',
                'grading_evidence',
                'teacher_work',
            ],
        ];
    }
}
