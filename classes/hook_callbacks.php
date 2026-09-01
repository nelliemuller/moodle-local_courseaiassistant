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
            $drawerstyle = <<<'CSS'
<style>
.local-courseaiassistant-edge-launcher {
    position: fixed !important;
    right: 18px !important;
    bottom: 24px !important;
    z-index: 1045 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    min-height: 48px !important;
    padding: 10px 18px !important;
    border: 0 !important;
    border-radius: 999px !important;
    color: #fff !important;
    background: var(--bs-primary, var(--primary, #0f6cbf)) !important;
    box-shadow: 0 8px 24px rgba(0,0,0,.20) !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    cursor: pointer !important;
}

.local-courseaiassistant-edge-launcher:hover {
    filter: brightness(.96);
    color: #fff !important;
    text-decoration: none !important;
}

.local-courseaiassistant-edge-launcher.is-bottomcenter {
    right: 50% !important;
    left: auto !important;
    transform: translateX(50%) !important;
}

.local-courseaiassistant-edge-launcher.is-bottomleft {
    right: auto !important;
    left: 18px !important;
    bottom: 96px !important;
}

.local-courseaiassistant-edge-launcher.is-askgemini {
    top: var(--course-ai-top-right-offset, 150px) !important;
    right: var(--course-ai-top-right-right-offset, 96px) !important;
    bottom: auto !important;
    left: auto !important;
    transform: none !important;
}

.local-courseaiassistant-edge-launcher.is-courseheader,
.local-courseaiassistant-edge-launcher.is-fullpage {
    position: static !important;
    margin-left: auto !important;
    box-shadow: none !important;
    text-decoration: none !important;
}

.local-courseaiassistant-edge-launcher.is-course-button {
    position: static !important;
    margin-left: auto !important;
    box-shadow: none !important;
}

.local-courseaiassistant-launcher-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    overflow: hidden;
    border-radius: 8px;
}

.local-courseaiassistant-launcher-icon img {
    display: block;
    width: 100%;
    height: 100%;
    padding: 2px;
    object-fit: contain;
    background: #fff;
}

.local-courseaiassistant-drawer {
    position: fixed !important;
    top: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    z-index: 1050 !important;
    width: min(460px, 38vw) !important;
    max-width: 100vw !important;
    height: 100vh !important;
    display: flex !important;
    flex-direction: column !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: #fff !important;
    box-shadow: -10px 0 32px rgba(0,0,0,.20) !important;
    transform: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    transition: opacity .18s ease, visibility .18s ease !important;
}

.local-courseaiassistant-drawer.is-open {
    transform: none !important;
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
}

.local-courseaiassistant-drawer.is-restoring {
    transition: none !important;
}

/*
 * When the embedded AI Course Assistant uses its native
 * course-ai-collapsed state, the surrounding universal drawer
 * must shrink with it instead of remaining 100vh tall.
 *
 * The local plugin adapts its outer shell to the minimized state.
 */
.local-courseaiassistant-drawer.is-minimized {
    top: auto !important;
    bottom: 24px !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
}

.local-courseaiassistant-drawer.is-minimized
.local-courseaiassistant-drawer-top {
    display: none !important;
}

.local-courseaiassistant-drawer.is-minimized
.local-courseaiassistant-drawer-content {
    flex: 0 0 auto !important;
    min-height: 0 !important;
    height: auto !important;
    overflow: visible !important;
}

.local-courseaiassistant-drawer.is-minimized
.local_courseaiassistant {
    height: auto !important;
    min-height: 0 !important;
}

.local-courseaiassistant-drawer.is-minimized
.course-ai-chat-box {
    height: auto !important;
    min-height: 0 !important;
}

.local-courseaiassistant-drawer-top {
    display: none !important;
}

.local-courseaiassistant-drawer-toolbar-brand {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 26px !important;
    height: 26px !important;
    margin: 0 4px !important;
    overflow: hidden !important;
    border-radius: 50% !important;
}

.local-courseaiassistant-drawer-toolbar-brand img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.local-courseaiassistant-drawer-drag-indicator {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 28px !important;
    height: 28px !important;
    color: #4a4a4a !important;
    font-size: 22px !important;
    line-height: 1 !important;
}

.local-courseaiassistant-drawer-toolbar-spacer {
    flex: 1 1 auto !important;
    align-self: stretch !important;
}

.local-courseaiassistant-drawer.is-popped-out
.course-ai-modern-header {
    cursor: move !important;
    touch-action: none !important;
    user-select: none !important;
}

.local-courseaiassistant-drawer-popout {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 34px !important;
    height: 34px !important;
    padding: 6px !important;
    border: 0 !important;
    border-radius: 50% !important;
    background: transparent !important;
    color: #fff !important;
    cursor: pointer !important;
}

.local-courseaiassistant-drawer-popout:hover {
    background: rgba(255, 255, 255, .16) !important;
}

.local-courseaiassistant-drawer-popout svg {
    width: 20px !important;
    height: 20px !important;
    fill: none !important;
    stroke: currentColor !important;
    stroke-width: 2 !important;
}

.local-courseaiassistant-drawer.is-docked-right {
    top: 0 !important;
    right: var(--course-ai-docked-right-offset, 56px) !important;
    bottom: 0 !important;
    left: auto !important;
    width: min(460px, 38vw) !important;
    height: 100vh !important;
    border-radius: 0 !important;
    box-shadow: -10px 0 32px rgba(0, 0, 0, .20) !important;
}

.local-courseaiassistant-drawer.is-popped-out {
    top: var(--course-ai-drawer-top, 64px) !important;
    right: auto !important;
    bottom: auto !important;
    left: var(--course-ai-drawer-left, 64px) !important;
    width: min(var(--course-ai-drawer-width, 560px), calc(100vw - 24px)) !important;
    height: min(var(--course-ai-drawer-height, 720px), calc(100vh - 24px)) !important;
    max-height: calc(100vh - 24px) !important;
    border-radius: 18px !important;
    box-shadow: 0 18px 54px rgba(0, 0, 0, .30) !important;
}

.local-courseaiassistant-resize-handle {
    display: none;
}

.local-courseaiassistant-drawer.is-popped-out
.local-courseaiassistant-resize-handle {
    position: absolute;
    right: 3px;
    bottom: 3px;
    z-index: 20;
    display: block;
    width: 24px;
    height: 24px;
    cursor: nwse-resize;
    touch-action: none;
}

.local-courseaiassistant-drawer.is-popped-out
.local-courseaiassistant-resize-handle::after {
    position: absolute;
    right: 4px;
    bottom: 4px;
    width: 10px;
    height: 10px;
    content: '';
    border-right: 2px solid rgba(80, 92, 112, .75);
    border-bottom: 2px solid rgba(80, 92, 112, .75);
}

.local-courseaiassistant-drawer-top strong {
    font-size: 17px !important;
}

.local-courseaiassistant-drawer-close {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 34px !important;
    height: 34px !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 50% !important;
    background: transparent !important;
    color: #fff !important;
    font-size: 26px !important;
    line-height: 1 !important;
    cursor: pointer !important;
}

.local-courseaiassistant-drawer .course-ai-header-subtitle {
    display: none !important;
}

.local-courseaiassistant-drawer .course-ai-header-controls
.local-courseaiassistant-drawer-popout,
.local-courseaiassistant-drawer .course-ai-header-controls
.local-courseaiassistant-drawer-close {
    flex: 0 0 34px !important;
    margin: 0 !important;
}

.local-courseaiassistant-drawer .course-ai-header-controls
.local-courseaiassistant-drawer-close:hover,
.local-courseaiassistant-drawer .course-ai-header-controls
.local-courseaiassistant-drawer-close:focus-visible {
    background: rgba(255, 255, 255, .16) !important;
}

.local-courseaiassistant-drawer-content {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    background: transparent !important;
}

.local-courseaiassistant-drawer .local_courseaiassistant {
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
}

.local-courseaiassistant-drawer .course-ai-chat-box {
    position: relative !important;
    top: auto !important;
    right: auto !important;
    bottom: auto !important;
    left: auto !important;
    width: 100% !important;
    max-width: none !important;
    height: auto !important;
    margin: 0 !important;
}

.local-courseaiassistant-drawer
.course-ai-chat-box.course-ai-placement-floating,
.local-courseaiassistant-drawer
.course-ai-chat-box.course-ai-placement-floating.course-ai-open {
    position: relative !important;
    inset: auto !important;
    width: 100% !important;
    max-width: none !important;
}

.local-courseaiassistant-drawer .course-ai-launcher-button,
.local-courseaiassistant-drawer .course-ai-open-row,
.local-courseaiassistant-drawer .course-ai-collapse-button {
    display: none !important;
}

.local-courseaiassistant-drawer .course-ai-panel {
    display: block !important;
    position: static !important;
    width: 100% !important;
    max-width: none !important;
    height: auto !important;
    max-height: none !important;
    transform: none !important;
    visibility: visible !important;
    opacity: 1 !important;
}

body.local-courseaiassistant-drawer-open
.local-courseaiassistant-edge-launcher {
    display: none !important;
}

@media (max-width: 767.98px) {
    .local-courseaiassistant-drawer {
        width: 100vw !important;
    }

    .local-courseaiassistant-drawer.is-docked-right,
    .local-courseaiassistant-drawer.is-popped-out {
        position: fixed !important;
        inset: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        max-height: none !important;
        border-radius: 0 !important;
    }

    .local-courseaiassistant-drawer.is-docked-right {
        right: 0 !important;
    }

    .local-courseaiassistant-drawer-popout {
        display: none !important;
    }

    .local-courseaiassistant-resize-handle {
        display: none !important;
    }

    .local-courseaiassistant-edge-launcher {
        right: 12px !important;
        bottom: 16px !important;
    }

    .local-courseaiassistant-edge-launcher.is-bottomcenter {
        right: 50% !important;
        left: auto !important;
    }

    .local-courseaiassistant-edge-launcher.is-bottomleft {
        right: auto !important;
        left: 12px !important;
        bottom: 84px !important;
    }

    .local-courseaiassistant-edge-launcher.is-askgemini {
        top: var(--course-ai-top-right-offset, 84px) !important;
        right: var(--course-ai-top-right-right-offset, 64px) !important;
        bottom: auto !important;
        left: auto !important;
    }
}
</style>
CSS;

            self::$assistanthtml =
                $drawerstyle .
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
