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


namespace local_courseaiassistant\context;

defined('MOODLE_INTERNAL') || die();

class context_manager {

    /**
     * Detect the user's intent.
     *
     * @param string $question
     * @return string
     */
    public static function detect_intent(string $question): string {

        $question = core_text::strtolower(trim($question));

        if (preg_match('/\b(block|blocks)\b/u', $question)) {
            return 'blocks';
        }

        if (preg_match('/\b(forum|discussion|post|reply|replies)\b/u', $question)) {
            return 'forum';
        }

        if (preg_match('/\b(assignment)\b/u', $question)) {
            return 'assignment';
        }

        if (preg_match('/\b(quiz)\b/u', $question)) {
            return 'quiz';
        }

        if (preg_match('/\b(book|page|file|folder|url|text|ims)\b/u', $question)) {
            return 'resource';
        }

        if (preg_match('/\b(activity|activities)\b/u', $question)) {
            return 'activity';
        }

        if (preg_match('/\b(section|sections|topic|topics|week|weeks)\b/u', $question)) {
            return 'section';
        }

        return 'general';
    }
}
