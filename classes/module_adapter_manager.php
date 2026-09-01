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

use local_courseaiassistant\module_adapter\adapter_interface;
use local_courseaiassistant\module_adapter\assign_adapter;
use local_courseaiassistant\module_adapter\forum_adapter;
use local_courseaiassistant\module_adapter\generic_adapter;
use local_courseaiassistant\module_adapter\quiz_adapter;

/**
 * Dispatch specialist Moodle module intelligence.
 *
 * Unknown core or third-party activities automatically receive
 * generic fallback intelligence.
 *
 * @package local_courseaiassistant
 */
final class module_adapter_manager {

    /** @var \stdClass */
    private \stdClass $course;

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
    }

    /**
     * Resolve specialist evidence for all accessible modules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve_all(): array {
        global $USER;

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $out = [];

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

            $adapter =
                $this->adapter_for(
                    (string)$cm->modname
                );

            $evidence =
                $adapter->resolve(
                    $cm,
                    $context,
                    $this->course
                );

            $out[] = [
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

                'adapter' =>
                    $adapter->modname(),

                'evidence' =>
                    $evidence,
            ];
        }

        return $out;
    }

    /**
     * Resolve an adapter.
     *
     * Specialist adapters are additive.
     * Unknown modules always receive generic fallback.
     *
     * @param string $modname
     * @return adapter_interface
     */
    private function adapter_for(
        string $modname
    ): adapter_interface {
        switch ($modname) {
            case 'forum':
                return new forum_adapter();

            case 'assign':
                return new assign_adapter();

            case 'quiz':
                return new quiz_adapter();

            default:
                return new generic_adapter(
                    $modname
                );
        }
    }
}
