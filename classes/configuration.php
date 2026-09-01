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
 * Site-wide configuration for the standalone AI Course Assistant.
 *
 * The plugin owns its settings and presentation independently.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class configuration {

    /**
     * Return the standalone site configuration.
     *
     * @return array<string, mixed>
     */
    public static function site_defaults(): array {
        $name = trim(
            (string)get_config(
                'local_courseaiassistant',
                'assistantname'
            )
        );

        if ($name === '') {
            $name = get_string(
                'pluginname',
                'local_courseaiassistant'
            );
        }

        $languagemode = (string)get_config(
            'local_courseaiassistant',
            'responselanguage'
        );

        if (!in_array(
            $languagemode,
            ['user', 'question', 'site', 'fixed'],
            true
        )) {
            $languagemode = 'user';
        }

        $fixedlanguage = trim(
            (string)get_config(
                'local_courseaiassistant',
                'fixedlanguage'
            )
        );

        if ($fixedlanguage === '') {
            $fixedlanguage = 'en';
        }

        $aiprovider = (string)get_config(
            'local_courseaiassistant',
            'aiprovider'
        );

        if (!in_array(
            $aiprovider,
            ['gemini', 'openai'],
            true
        )) {
            $aiprovider = 'gemini';
        }

        $geminimodel = trim(
            (string)get_config(
                'local_courseaiassistant',
                'model'
            )
        );

        if ($geminimodel === '') {
            $geminimodel = 'gemini-3.7-flash';
        }

        $openaimodel = trim(
            (string)get_config(
                'local_courseaiassistant',
                'openaimodel'
            )
        );

        if ($openaimodel === '') {
            $openaimodel = 'gpt-5.6-terra';
        }

        $openaireasoning = (string)get_config(
            'local_courseaiassistant',
            'openaireasoning'
        );

        if (!in_array(
            $openaireasoning,
            ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
            true
        )) {
            $openaireasoning = 'medium';
        }

        $allowdashboard = get_config(
            'local_courseaiassistant',
            'allowdashboard'
        );

        $launcherplacement = (string)get_config(
            'local_courseaiassistant',
            'launcherplacement'
        );
        // Preserve the original floating placement when upgrading from RC2.
        if ($launcherplacement === 'floating') {
            $launcherplacement = 'bottomright';
        }
        if (!in_array(
            $launcherplacement,
            ['bottomright', 'bottomcenter', 'bottomleft', 'courseheader', 'fullpage', 'askgemini'],
            true
        )) {
            $launcherplacement = 'bottomright';
        }

        $iconsource = (string)get_config(
            'local_courseaiassistant',
            'iconsource'
        );
        if (!in_array($iconsource, ['default', 'sitelogo', 'custom'], true)) {
            $iconsource = 'default';
        }

        return [
            'assistantname' => $name,
            'languagemode' => $languagemode,
            'fixedlanguage' => $fixedlanguage,
            'allowdashboard' =>
                $allowdashboard === false
                    ? true
                    : (bool)$allowdashboard,
            'launcherplacement' => $launcherplacement,
            'aiprovider' => $aiprovider,
            'geminimodel' => $geminimodel,
            'openaimodel' => $openaimodel,
            'openaireasoning' => $openaireasoning,
            'iconsource' => $iconsource,
        ];
    }

    /**
     * Compatibility entrypoint for existing intelligence/UI services.
     *
     * The argument is retained for API compatibility.
     *
     * @param object|null $unused
     * @return array<string, mixed>
     */
    public static function resolve(
        ?object $unused = null
    ): array {
        return self::site_defaults();
    }

    /**
     * Resolve requested AI response language.
     *
     * @param array<string, mixed> $config
     * @return string
     */
    public static function response_language(
        array $config
    ): string {
        global $CFG;

        switch ($config['languagemode'] ?? 'user') {
            case 'question':
                return 'match-question';

            case 'site':
                return clean_param(
                    (string)($CFG->lang ?? 'en'),
                    PARAM_ALPHANUMEXT
                );

            case 'fixed':
                return clean_param(
                    (string)($config['fixedlanguage'] ?? 'en'),
                    PARAM_ALPHANUMEXT
                );

            case 'user':
            default:
                return clean_param(
                    (string)current_language(),
                    PARAM_ALPHANUMEXT
                );
        }
    }
}
