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

final class hook_callbacks {

    private static string $assistanthtml = '';
    private static bool $prepared = false;

    /**
     * Prepare the existing AI Course Assistant floating UI.
     *
     * Uses Moodle page CONTEXT, following Moodle's own
     * Course Assist lifecycle instead of depending on
     * $PAGE->course during after_http_headers.
     */
    public static function prepare_assistant(
        \core\hook\output\after_http_headers $hook
    ): void {
        global $PAGE, $USER;

        if (self::$prepared) {
            return;
        }

        if (during_initial_install()) {
            return;
        }

        // The dedicated assistant page renders its own complete interface.
        // Injecting the course launcher there would create a second assistant,
        // especially inside the persistent browser pop-out window.
        if ($PAGE->url && $PAGE->url->get_path() === '/local/courseaiassistant/assistant.php') {
            return;
        }

        if (!(bool)get_config('local_courseaiassistant', 'enabled')) {
            return;
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        /*
         * Never show AI Course Assistant on the Moodle front page.
         *
         * The universal assistant belongs only to real course contexts.
         */
        if (
            $PAGE->pagetype === 'site-index' ||
            (!empty($PAGE->course) && (int)$PAGE->course->id === SITEID)
        ) {
            return;
        }

        // Never inject into layouts where Moodle itself avoids assist UI.
        if (
            in_array(
                $PAGE->pagelayout,
                ['maintenance', 'print', 'redirect', 'embedded'],
                true
            )
        ) {
            return;
        }

        /*
         * Resolve the course from Moodle's PAGE CONTEXT.
         *
         * Course page     => CONTEXT_COURSE
         * Activity/forum  => CONTEXT_MODULE
         */
        $pagecontext = $PAGE->context ?? null;

        if (!$pagecontext) {
            return;
        }

        if ($pagecontext->contextlevel === CONTEXT_COURSE) {
            $coursecontext = $pagecontext;

        } else if ($pagecontext->contextlevel === CONTEXT_MODULE) {
            $coursecontext = $pagecontext->get_course_context(false);

        } else {
            return;
        }

        if (!$coursecontext) {
            return;
        }

        $courseid = (int)$coursecontext->instanceid;

        if ($courseid <= 0 || $courseid === SITEID) {
            return;
        }

        if (
            !has_capability(
                'local/courseaiassistant:use',
                $coursecontext,
                $USER
            )
        ) {
            return;
        }

        try {
            $course = get_course($courseid);

            $config =
                \local_courseaiassistant\configuration::resolve();

            $assistantname = (string)($config['assistantname'] ?? get_string('pluginname', 'local_courseaiassistant'));
            $launcherplacement = (string)($config['launcherplacement'] ?? 'bottomright');
            if (!in_array(
                $launcherplacement,
                ['bottomright', 'bottomcenter', 'bottomleft', 'courseheader', 'fullpage', 'askgemini'],
                true
            )) {
                $launcherplacement = 'bottomright';
            }
            $assistanticonurl = \local_courseaiassistant\ui::assistant_icon_url($config);
            $assistanticoncontent = \local_courseaiassistant\ui::assistant_icon_content($assistanticonurl);
            $assistant =
                \local_courseaiassistant\ui::render_course(
                    $course,
                    $config,
                    'floating',
                    [
                        'returnurl' => $PAGE->url->out(false),
                    ]
                );

            $assistant =
                \html_writer::div(
                    $assistant,
                    'local_courseaiassistant'
                );

            /*
             * Assistant drawer shell.
             */
            $launcherclass = 'local-courseaiassistant-edge-launcher is-' . $launcherplacement;
            $fullpageurl = new \moodle_url(
                '/local/courseaiassistant/assistant.php',
                [
                    'courseid' => $courseid,
                    'returnurl' => $PAGE->url->out(false),
                ]
            );
            if ($launcherplacement === 'fullpage') {
                $launcher =
                    '<a href="' . s($fullpageurl->out(false)) . '"
                    class="' . s($launcherclass) . '"
                    data-courseaiassistant-placement="fullpage">
                    <span class="local-courseaiassistant-launcher-icon" aria-hidden="true">' . $assistanticoncontent . '</span>
                    ' . s(get_string('openassistant', 'local_courseaiassistant', $assistantname)) . '
                </a>';
            } else {
                $launcher =
                    '<button type="button"
                    class="' . s($launcherclass) . '"
                    data-courseaiassistant-placement="' . s($launcherplacement) . '"
                    aria-controls="local-courseaiassistant-drawer"
                    aria-expanded="false"
                    style="
                        width:auto !important;
                        height:48px !important;
                        min-width:0 !important;
                        min-height:48px !important;
                        max-height:48px !important;
                        padding:0 18px !important;
                        display:inline-flex !important;
                        align-items:center !important;
                        justify-content:center !important;
                        white-space:nowrap !important;
                        border-radius:999px !important;
                        z-index:1045 !important;
                    ">
                    <span class="local-courseaiassistant-launcher-icon" aria-hidden="true">' . $assistanticoncontent . '</span>
                    ' . s($assistantname) . '
                </button>';
            }

            $close =
                '<button type="button"
                    class="local-courseaiassistant-drawer-close"
                    aria-label="' . s(get_string('closeassistant', 'local_courseaiassistant')) . '">×</button>';
            $popout = '';
            if ($launcherplacement !== 'fullpage') {
                $popout =
                    '<button type="button"
                    class="local-courseaiassistant-drawer-popout"
                    aria-label="' . s(get_string('popoutchat', 'local_courseaiassistant')) . '"
                    title="' . s(get_string('popoutchat', 'local_courseaiassistant')) . '"
                    data-popout-label="' . s(get_string('popoutchat', 'local_courseaiassistant')) . '"
                    data-dock-label="' . s(get_string('dockchat', 'local_courseaiassistant')) . '"
                    aria-pressed="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <rect x="4" y="5" width="16" height="14" rx="2"></rect>
                        <path d="M9 9h6v6H9z"></path>
                    </svg>
                </button>';
            }

            /*
             * Self-contained presentation layer.
             *
             * This is deliberately scoped only to the local assistant.
             * It does not modify Moodle navigation, drawers, course index,
             * page regions or theme layouts.
             */
            self::$assistanthtml =
                $launcher .
                '<aside id="local-courseaiassistant-drawer"
                    class="local-courseaiassistant-drawer"
                    aria-hidden="true">' .
                    '<div class="local-courseaiassistant-drawer-top" title="' .
                        s(get_string('movechat', 'local_courseaiassistant')) . '">' .
                        ($launcherplacement !== 'fullpage' ?
                            '<span class="local-courseaiassistant-drawer-toolbar-brand" aria-hidden="true">' .
                                $assistanticoncontent . '</span>' .
                            '<span class="local-courseaiassistant-drawer-drag-indicator" aria-hidden="true">⋮</span>' .
                            '<span class="local-courseaiassistant-drawer-toolbar-spacer" aria-hidden="true"></span>' : '') .
                        $popout .
                        $close .
                    '</div>' .
                    '<div class="local-courseaiassistant-drawer-content">' .
                        $assistant .
                    '</div>' .
                '</aside>';

            $PAGE->requires->js_call_amd(
                'local_courseaiassistant/drawer',
                'init'
            );

            self::$prepared = (self::$assistanthtml !== '');

        } catch (\Throwable $e) {
            /*
             * Critical safety rule:
             * an assistant failure must NEVER break Moodle.
             */
            debugging(
                'Universal AI Course Assistant skipped: ' .
                $e->getMessage(),
                DEBUG_DEVELOPER
            );

            self::$assistanthtml = '';
            self::$prepared = false;
        }
    }

    /**
     * Output only the HTML already prepared safely above.
     */
    public static function output_assistant(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        if (self::$assistanthtml !== '') {
            $hook->add_html(self::$assistanthtml);
        }
    }
}
