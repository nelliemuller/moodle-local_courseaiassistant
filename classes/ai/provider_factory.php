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

namespace local_courseaiassistant\ai;

defined('MOODLE_INTERNAL') || die();

/** Select the AI provider configured by the Moodle administrator. */
class provider_factory {
    public static function make(array $config = []): provider_interface {
        $provider = (string)($config['aiprovider'] ?? get_config('local_courseaiassistant', 'aiprovider'));
        if ($provider === 'openai') {
            return new openai_provider($config);
        }
        return new gemini_provider($config);
    }
}
