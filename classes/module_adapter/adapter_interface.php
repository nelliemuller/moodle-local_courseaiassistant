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
 * Contract for deep Moodle activity intelligence.
 *
 * Specialist adapters enrich generic Moodle evidence.
 * They never replace Moodle permissions and never mutate Moodle.
 *
 * @package local_courseaiassistant
 */
interface adapter_interface {

    /**
     * Moodle modname handled by this adapter.
     *
     * @return string
     */
    public function modname(): string;

    /**
     * Read specialist evidence for one activity instance.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param \stdClass $course
     * @return array<string, mixed>
     */
    public function resolve(
        \cm_info $cm,
        \context_module $context,
        \stdClass $course
    ): array;
}
