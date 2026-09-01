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
 * Safe fallback adapter for any Moodle activity.
 *
 * @package local_courseaiassistant
 */
final class generic_adapter implements adapter_interface {

    /** @var string */
    private string $modname;

    /**
     * @param string $modname
     */
    public function __construct(string $modname) {
        $this->modname = $modname;
    }

    public function modname(): string {
        return $this->modname;
    }

    public function resolve(
        \cm_info $cm,
        \context_module $context,
        \stdClass $course
    ): array {
        return [
            'adapter' =>
                'generic',

            'modname' =>
                $this->modname,

            'cmid' =>
                (int)$cm->id,

            /*
             * Generic evidence is already supplied by
             * universalmodules, activitystates and activitycontent.
             *
             * This adapter deliberately does not invent
             * module-specific semantics.
             */
            'specialistevidence' =>
                [],
        ];
    }
}
