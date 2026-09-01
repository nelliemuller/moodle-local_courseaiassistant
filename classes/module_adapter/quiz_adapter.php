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
 * Quiz specialist evidence.
 *
 * @package local_courseaiassistant
 */
final class quiz_adapter implements adapter_interface {

    public function modname(): string {
        return 'quiz';
    }

    public function resolve(
        \cm_info $cm,
        \context_module $context,
        \stdClass $course
    ): array {
        global $DB;

        $quiz =
            $DB->get_record(
                'quiz',
                ['id' => (int)$cm->instance],
                '*',
                IGNORE_MISSING
            );

        if (!$quiz) {
            return [];
        }

        return [
            'adapter' =>
                'quiz',

            'timeopen' =>
                (int)($quiz->timeopen ?? 0),

            'timeclose' =>
                (int)($quiz->timeclose ?? 0),

            'timelimit' =>
                (int)($quiz->timelimit ?? 0),

            'attemptsallowed' =>
                (int)($quiz->attempts ?? 0),

            'grademethod' =>
                (int)($quiz->grademethod ?? 0),

            'grade' =>
                (float)($quiz->grade ?? 0),

            'sumgrades' =>
                (float)($quiz->sumgrades ?? 0),

            'questionsperpage' =>
                (int)($quiz->questionsperpage ?? 0),

            'browsersecurity' =>
                (string)($quiz->browsersecurity ?? ''),

            'completion' => [
                'attemptsexhausted' =>
                    !empty(
                        $quiz->completionattemptsexhausted
                    ),

                'passorexhausted' =>
                    !empty(
                        $quiz->completionpassorattemptsexhausted
                    ),
            ],

            'capabilities' => [
                'attempt' =>
                    has_capability(
                        'mod/quiz:attempt',
                        $context
                    ),

                'manage' =>
                    has_capability(
                        'mod/quiz:manage',
                        $context
                    ),

                'grade' =>
                    has_capability(
                        'mod/quiz:grade',
                        $context
                    ),

                'viewreports' =>
                    has_capability(
                        'mod/quiz:viewreports',
                        $context
                    ),
            ],
        ];
    }
}
