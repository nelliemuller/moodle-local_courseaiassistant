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
 * Assignment specialist evidence.
 *
 * @package local_courseaiassistant
 */
final class assign_adapter implements adapter_interface {

    public function modname(): string {
        return 'assign';
    }

    public function resolve(
        \cm_info $cm,
        \context_module $context,
        \stdClass $course
    ): array {
        global $DB;

        $assign =
            $DB->get_record(
                'assign',
                ['id' => (int)$cm->instance],
                '*',
                IGNORE_MISSING
            );

        if (!$assign) {
            return [];
        }

        return [
            'adapter' =>
                'assign',

            'duedate' =>
                (int)($assign->duedate ?? 0),

            'cutoffdate' =>
                (int)($assign->cutoffdate ?? 0),

            'gradingduedate' =>
                (int)($assign->gradingduedate ?? 0),

            'allowsubmissionsfromdate' =>
                (int)($assign->allowsubmissionsfromdate ?? 0),

            'grade' =>
                (float)($assign->grade ?? 0),

            'teamsubmission' =>
                !empty($assign->teamsubmission),

            'requireallteammemberssubmit' =>
                !empty($assign->requireallteammemberssubmit),

            'attemptreopenmethod' =>
                (string)($assign->attemptreopenmethod ?? ''),

            'maxattempts' =>
                (int)($assign->maxattempts ?? 0),

            'markingworkflow' =>
                !empty($assign->markingworkflow),

            'markingallocation' =>
                !empty($assign->markingallocation),

            'sendnotifications' =>
                !empty($assign->sendnotifications),

            'completion' => [
                'submit' =>
                    !empty($assign->completionsubmit),
            ],

            'capabilities' => [
                'submit' =>
                    has_capability(
                        'mod/assign:submit',
                        $context
                    ),

                'grade' =>
                    has_capability(
                        'mod/assign:grade',
                        $context
                    ),

                'viewgrades' =>
                    has_capability(
                        'mod/assign:viewgrades',
                        $context
                    ),
            ],
        ];
    }
}
