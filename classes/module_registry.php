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
 * Universal registry for all installed Moodle activity/resource modules.
 *
 * Discovers core and third-party mod plugins dynamically and records
 * the Moodle features each plugin declares through [modname]_supports().
 *
 * Read only.
 *
 * @package local_courseaiassistant
 */
final class module_registry {

    /**
     * Build the registry of installed Moodle mod plugins.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolve_all(): array {
        global $CFG;

        $manager =
            \core\plugin_manager::instance();

        $plugins =
            $manager->get_plugins_of_type('mod');

        $result = [];

        foreach ($plugins as $name => $plugininfo) {
            $name =
                clean_param(
                    (string)$name,
                    PARAM_COMPONENT
                );

            if ($name === '') {
                continue;
            }

            /*
             * Load lib.php if the module provides it so that the
             * standard [modname]_supports() callback is available.
             */
            $libfile =
                $CFG->dirroot .
                '/mod/' .
                $name .
                '/lib.php';

            if (is_readable($libfile)) {
                require_once($libfile);
            }

            $component =
                'mod_' . $name;

            $features =
                $this->feature_support($name);

            $result[$component] = [
                'component' =>
                    $component,

                'modname' =>
                    $name,

                'displayname' =>
                    $this->plugin_display_name(
                        $component,
                        $name
                    ),

                'installed' =>
                    true,

                'source' =>
                    $this->plugin_source($plugininfo),

                'features' =>
                    $features,

                'callbacks' =>
                    $this->known_callbacks($name),

                'specialistadapter' =>
                    $this->specialist_adapter($name),
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Query common Moodle module feature declarations.
     *
     * Unknown feature declarations remain null rather than being
     * converted into false assumptions.
     *
     * @param string $modname
     * @return array<string, mixed>
     */
    private function feature_support(
        string $modname
    ): array {
        $features = [];

        $constants = [
            'groups' =>
                'FEATURE_GROUPS',

            'groupings' =>
                'FEATURE_GROUPINGS',

            'modintro' =>
                'FEATURE_MOD_INTRO',

            'showdescription' =>
                'FEATURE_SHOW_DESCRIPTION',

            'completion' =>
                'FEATURE_COMPLETION',

            'completiontracksviews' =>
                'FEATURE_COMPLETION_TRACKS_VIEWS',

            'completionhasrules' =>
                'FEATURE_COMPLETION_HAS_RULES',

            'gradehasgrade' =>
                'FEATURE_GRADE_HAS_GRADE',

            'gradeoutcomes' =>
                'FEATURE_GRADE_OUTCOMES',

            'advancedgrading' =>
                'FEATURE_ADVANCED_GRADING',

            'ratings' =>
                'FEATURE_RATE',

            'backup' =>
                'FEATURE_BACKUP_MOODLE2',

            'plagiarism' =>
                'FEATURE_PLAGIARISM',

            'modpurpose' =>
                'FEATURE_MOD_PURPOSE',
        ];

        foreach ($constants as $key => $constantname) {
            if (!defined($constantname)) {
                $features[$key] = null;
                continue;
            }

            $features[$key] =
                $this->supports(
                    $modname,
                    constant($constantname)
                );
        }

        return $features;
    }

    /**
     * Call Moodle's standard [modname]_supports() callback safely.
     *
     * @param string $modname
     * @param mixed $feature
     * @return mixed
     */
    private function supports(
        string $modname,
        $feature
    ) {
        $callback =
            $modname . '_supports';

        if (!function_exists($callback)) {
            return null;
        }

        try {
            return $callback($feature);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Record useful optional callbacks exposed by a module.
     *
     * This does not call write callbacks.
     *
     * @param string $modname
     * @return array<string, bool>
     */
    private function known_callbacks(
        string $modname
    ): array {
        return [
            'supports' =>
                function_exists(
                    $modname . '_supports'
                ),

            'getcompletionrules' =>
                function_exists(
                    $modname .
                    '_get_completion_active_rule_descriptions'
                ),

            'gradingareas' =>
                function_exists(
                    $modname .
                    '_grading_areas_list'
                ),

            'coursemoduleinfo' =>
                function_exists(
                    $modname .
                    '_get_coursemodule_info'
                ),

            'fileinfo' =>
                function_exists(
                    $modname .
                    '_get_file_info'
                ),
        ];
    }

    /**
     * Identify specialist intelligence already available or planned.
     *
     * Generic fallback remains available regardless.
     *
     * @param string $modname
     * @return string
     */
    private function specialist_adapter(
        string $modname
    ): string {
        $existing = [
            'forum' =>
                'forum',

            'book' =>
                'book',

            'page' =>
                'page',

            'resource' =>
                'resource',

            'folder' =>
                'folder',

            'url' =>
                'url',

            'label' =>
                'label',
        ];

        return $existing[$modname]
            ?? 'generic';
    }

    /**
     * Get plugin name without failing on incomplete language packs.
     *
     * @param string $component
     * @param string $fallback
     * @return string
     */
    private function plugin_display_name(
        string $component,
        string $fallback
    ): string {
        try {
            $name =
                get_string(
                    'pluginname',
                    $component
                );

            if (
                is_string($name) &&
                trim($name) !== ''
            ) {
                return trim($name);
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return $fallback;
    }

    /**
     * Identify whether Moodle considers the plugin standard or additional.
     *
     * @param object $plugininfo
     * @return string
     */
    private function plugin_source(
        object $plugininfo
    ): string {
        try {
            if (
                method_exists(
                    $plugininfo,
                    'is_standard'
                )
            ) {
                return $plugininfo->is_standard()
                    ? 'standard'
                    : 'additional';
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return 'unknown';
    }
}
