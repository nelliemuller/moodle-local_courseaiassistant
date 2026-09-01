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


namespace local_courseaiassistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared UI renderer for all assistant placements.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {

    /**
     * Resolve the configured assistant image URL.
     */
    public static function assistant_icon_url(array $config): string {
        global $OUTPUT;

        $source = (string)($config['iconsource'] ?? 'default');
        if ($source === 'sitelogo') {
            $logo = $OUTPUT->get_compact_logo_url(100, 100);
            if (!$logo) {
                $logo = $OUTPUT->get_logo_url();
            }
            return $logo ? $logo->out(false) : '';
        }

        if ($source === 'custom') {
            $filename = trim((string)get_config('local_courseaiassistant', 'assistantimage'));
            $context = \context_system::instance();
            $fs = get_file_storage();
            $file = false;
            if ($filename !== '') {
                $file = $fs->get_file($context->id, 'local_courseaiassistant', 'assistantimage', 0, '/', $filename);
            }
            if (!$file || $file->is_directory()) {
                $files = $fs->get_area_files(
                    $context->id,
                    'local_courseaiassistant',
                    'assistantimage',
                    0,
                    'timemodified DESC, id DESC',
                    false
                );
                $file = $files ? reset($files) : false;
            }
            if ($file && !$file->is_directory()) {
                return \moodle_url::make_pluginfile_url(
                    $context->id,
                    'local_courseaiassistant',
                    'assistantimage',
                    0,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false);
            }
        }

        return '';
    }

    /**
     * Render the configured image, or the default star.
     */
    public static function assistant_icon_content(string $url): string {
        if ($url !== '') {
            return '<img src="' . s($url) . '" alt="">';
        }
        return '✦';
    }

    /**
     * Render the assistant for one course.
     *
     * @param \stdClass $course
     * @param array<string, mixed> $config
     * @param string|null $forcedplacement
     * @return string
     */
    public static function render_course(\stdClass $course, array $config, ?string $forcedplacement = null, array $state = []): string {
        global $CFG, $PAGE, $USER;

        $placement = $forcedplacement ?? (string)($config['placement'] ?? 'button');
        if (!in_array($placement, ['button', 'floating', 'fullpage'], true)) {
            $placement = 'button';
        }
        $isfullpage = ($placement === 'fullpage');

        $assistantname = trim((string)($config['assistantname'] ?? get_string('pluginname', 'local_courseaiassistant')));
        if ($assistantname === '') {
            $assistantname = get_string('pluginname', 'local_courseaiassistant');
        }
        $assistanticonurl = self::assistant_icon_url($config);
        $assistanticoncontent = self::assistant_icon_content($assistanticonurl);
        $assistanticonclass = $assistanticonurl !== '' ? ' course-ai-icon-image' : ' course-ai-icon-default';

        $rootid = 'course-ai-assistant-course-' . (int)$course->id;
        $endpoint = new \moodle_url('/local/courseaiassistant/chat.php');
        $historyendpoint = new \moodle_url('/local/courseaiassistant/history.php');
        $downloadendpoint = new \moodle_url('/local/courseaiassistant/download.php');
        $fullpageurl = new \moodle_url(
            '/local/courseaiassistant/assistant.php',
            ['courseid' => $course->id]
        );
        $conversationid = (int)($state['conversationid'] ?? 0);
        $returnurl = trim((string)($state['returnurl'] ?? ''));
        $ispopup = !empty($state['popup']);
        $welcome = get_string('welcomeuser', 'local_courseaiassistant', fullname($USER));
        $coursename = format_string($course->fullname);
        $welcomecourse = get_string('welcomecourse', 'local_courseaiassistant', $coursename);
        $askanything = get_string('askanything', 'local_courseaiassistant');
        $initialspeech = trim($welcome . ' ' . $welcomecourse . ' ' . $askanything);

        $coursecontext = \context_course::instance($course->id);
        $roles = get_user_roles($coursecontext, $USER->id, true);
        $rolenames = [];
        foreach ($roles as $role) {
            $name = role_get_name($role, $coursecontext, ROLENAME_ORIGINAL);
            if ($name !== '') {
                $rolenames[] = $name;
            }
        }
        if (is_siteadmin($USER)) {
            array_unshift($rolenames, get_string('siteadministrator', 'local_courseaiassistant'));
        }
        $rolenames = array_values(array_unique($rolenames));

        $rolelabels = [];
        if (is_siteadmin($USER)) {
            $rolelabels[] = 'administrator';
        }
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $rolelabels[] = 'manager';
        }
        if (has_capability('moodle/course:update', $coursecontext)) {
            $rolelabels[] = 'teacher';
        }
        if (empty($rolelabels)) {
            $rolelabels[] = 'participant';
        }
        $userrole = implode(', ', array_values(array_unique($rolelabels)));
        $userrolesdisplay = !empty($rolenames) ? implode(', ', $rolenames) : ucfirst($userrole);

        $userpicture = new \user_picture($USER);
        $userpicture->size = 1;
        $avatarurl = $userpicture->get_url($PAGE)->out(false);

        $themename = !empty($PAGE->theme->name) ? $PAGE->theme->name : (string)($CFG->theme ?? '');
        $usetheme = (bool)get_config('local_courseaiassistant', 'usethemecolor');
        $primary = '';
        if ($usetheme) {
            foreach (['brandcolor', 'primarycolor', 'primary'] as $setting) {
                $candidate = trim((string)get_config('theme_' . $themename, $setting));
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $candidate)) {
                    $primary = $candidate;
                    break;
                }
            }
        } else {
            $primary = trim((string)get_config('local_courseaiassistant', 'customprimary'));
        }
        $themestyle = '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
            $themestyle = '--course-ai-primary:' . $primary . ';--course-ai-primary-dark:' . $primary . ';';
        }

        $responselanguage = configuration::response_language($config);
        $strings = [
            'pluginname', 'userprofilepicture', 'moodleuser', 'readresponse', 'readaloud', 'stopreading',
            'historyunavailable', 'historyrequestfailed', 'conversationdefaulttitle', 'loadingconversations',
            'nopreviousconversations', 'downloadtxt', 'downloadhtml', 'renameconversation',
            'renamethisconversation', 'deletethisconversation', 'openchat', 'collapsechat',
            'askbeforedownload', 'downloadfailed', 'speechnotsupported', 'speechunderstandfailed',
            'deleteallconfirm', 'send', 'sending', 'unexpectedresponse', 'noresponse', 'serviceunavailable',
        ];
        $PAGE->requires->strings_for_js($strings, 'local_courseaiassistant');
        $PAGE->requires->js_call_amd('local_courseaiassistant/chat', 'init');

        $attrs = [
            'id' => $rootid,
            'class' => 'course-ai-chat-box course-ai-placement-' . $placement,
            'style' => $themestyle,
            'data-endpoint' => $endpoint->out(false),
            'data-historyendpoint' => $historyendpoint->out(false),
            'data-downloadendpoint' => $downloadendpoint->out(false),
            'data-fullpageurl' => $fullpageurl->out(false),
            'data-courseid' => (string)(int)$course->id,
            'data-sesskey' => sesskey(),
            'data-userid' => (string)(int)$USER->id,
            'data-userrole' => $userrole,
            'data-userrolesdisplay' => $userrolesdisplay,
            'data-useravatar' => $avatarurl,
            'data-assistantname' => $assistantname,
            'data-assistanticonurl' => $assistanticonurl,
            'data-responselanguage' => $responselanguage,
            'data-placement' => $placement,
            'data-conversationid' => $conversationid > 0 ? (string)$conversationid : '',
            'data-returnurl' => $returnurl,
            'data-popup' => $ispopup ? '1' : '0',
            'data-course-ai-modernized' => '1',
            'data-course-ai-history-ready' => '1',
        ];
        $attrtext = '';
        foreach ($attrs as $key => $value) {
            $attrtext .= ' ' . $key . '="' . s($value) . '"';
        }

        $launcher = '';

        if ($placement === 'fullpage' && $forcedplacement === null) {
            return '<div class="course-ai-fullpage-entry">' .
                '<a class="btn btn-primary course-ai-fullpage-entry-button" href="' . s($fullpageurl->out(false)) . '">' .
                s(get_string('openassistant', 'local_courseaiassistant', $assistantname)) . '</a></div>';
        }

        return '<div' . $attrtext . '>' .
            $launcher .
            '<div id="' . s($rootid) . '-panel" class="course-ai-panel">' .
            '<div class="course-ai-modern-header">' .
                '<div class="course-ai-header-star' . $assistanticonclass . '" aria-label="' . s($assistantname) . '">' . $assistanticoncontent . '</div>' .
                '<div class="course-ai-header-text">' .
                    '<div class="course-ai-header-title">' . s($assistantname) . '</div>' .
                '</div>' .
                '<div class="course-ai-header-controls">' .
                    ($ispopup ? '<button type="button" class="course-ai-popup-dock-button course-ai-control-button course-ai-header-action"' .
                        ' aria-label="' . s(get_string('dockchat', 'local_courseaiassistant')) . '"' .
                        ' title="' . s(get_string('dockchat', 'local_courseaiassistant')) . '">' .
                        '<svg class="course-ai-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' .
                        '<rect x="4" y="5" width="16" height="14" rx="2"></rect>' .
                        '<path d="M9 9h6v6H9z"></path></svg></button>' : '') .
                    '<button type="button" class="course-ai-new-chat-button course-ai-control-button course-ai-header-action"' .
                        ' aria-label="' . s(get_string('newchat', 'local_courseaiassistant')) . '"><svg class="course-ai-action-icon"
     viewBox="0 0 24 24"
     aria-hidden="true"
     focusable="false">
    <path d="M14 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
    <path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
</svg></button>' .
                    '<button type="button" class="course-ai-copy-chat-button course-ai-control-button course-ai-header-action"' .
                        ' aria-label="' . s(get_string('copychat', 'local_courseaiassistant')) . '"><svg class="course-ai-action-icon"
     viewBox="0 0 24 24"
     aria-hidden="true"
     focusable="false">
    <rect x="9" y="9" width="11" height="11" rx="2"></rect>
    <path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path>
</svg></button>' .
                    '<button type="button" class="course-ai-download-current-button course-ai-control-button course-ai-header-action"' .
                        ' aria-label="' . s(get_string('downloadcurrentchat', 'local_courseaiassistant')) . '"><svg class="course-ai-action-icon"
     viewBox="0 0 24 24"
     aria-hidden="true"
     focusable="false">
    <path d="M12 3v12"></path>
    <path d="M7 10l5 5 5-5"></path>
    <path d="M5 19v2h14v-2"></path>
</svg></button>' .
                    '<button type="button" class="course-ai-history-button course-ai-control-button course-ai-header-action"' .
                        ' aria-label="' . s(get_string('conversationhistory', 'local_courseaiassistant')) . '"><svg class="course-ai-action-icon"
     viewBox="0 0 24 24"
     aria-hidden="true"
     focusable="false">
    <path d="M3 12a9 9 0 1 0 3-6.7"></path>
    <path d="M3 4v5h5"></path>
    <path d="M12 7v5l3 2"></path>
</svg></button>' .
                    '<button type="button" class="course-ai-collapse-button course-ai-collapse-control"' .
                        ' aria-label="' . s(get_string('collapsechat', 'local_courseaiassistant')) . '">−</button>' .
                '</div>' .
            '</div>' .
            (!$isfullpage ? '<div class="course-ai-open-row"><a class="course-ai-open-fullpage-button" href="' . s($fullpageurl->out(false)) . '"' .
                ' title="' . s(get_string('openaiassistant', 'local_courseaiassistant')) . '"' .
                ' aria-label="' . s(get_string('openaiassistant', 'local_courseaiassistant')) . '">' .
                s(get_string('openaiassistant', 'local_courseaiassistant')) . '</a></div>' : '') .
            '<div class="course-ai-chat-history" role="log" aria-live="polite">' .
                '<div class="course-ai-message course-ai-assistant-message" data-initial-message="1" data-course-ai-decorated="1">' .
                    '<div class="course-ai-message-avatar' . $assistanticonclass . '" aria-label="' . s($assistantname) . '">' . $assistanticoncontent . '</div>' .
                    '<div class="course-ai-message-bubble">' .
                        '<p>' . s($welcome) . '</p>' .
                        '<p>' . s($welcomecourse) . '</p>' .
                        '<p>' . s($askanything) . '</p>' .
                    '</div>' .
                    '<button type="button" class="course-ai-read-button"' .
                        ' title="' . s(get_string('readresponse', 'local_courseaiassistant')) . '"' .
                        ' aria-label="' . s(get_string('readresponse', 'local_courseaiassistant')) . '"' .
                        ' data-text="' . s($initialspeech) . '">🔊</button>' .
                '</div>' .
            '</div>' .
            '<div class="course-ai-quick-actions" aria-label="' . s(get_string('suggestedquestions', 'local_courseaiassistant')) . '">' .
                '<button type="button" class="course-ai-quick-action" data-prompt="' . s(get_string('promptstarthere', 'local_courseaiassistant')) . '">🚀 ' . s(get_string('starthere', 'local_courseaiassistant')) . '</button>' .
                '<button type="button" class="course-ai-quick-action" data-prompt="' . s(get_string('promptwhatsnext', 'local_courseaiassistant')) . '">📚 ' . s(get_string('whatsnext', 'local_courseaiassistant')) . '</button>' .
                '<button type="button" class="course-ai-quick-action" data-prompt="' . s(get_string('promptexplain', 'local_courseaiassistant')) . '">💬 ' . s(get_string('explain', 'local_courseaiassistant')) . '</button>' .
                '<button type="button" class="course-ai-quick-action" data-prompt="' . s(get_string('promptsummarize', 'local_courseaiassistant')) . '">📄 ' . s(get_string('summarize', 'local_courseaiassistant')) . '</button>' .
            '</div>' .
            '<div class="course-ai-chat-controls">' .
                '<input type="text" class="course-ai-chat-input" placeholder="' . s(get_string('inputplaceholder', 'local_courseaiassistant')) . '"' .
                    ' autocomplete="off" aria-label="' . s(get_string('inputlabel', 'local_courseaiassistant')) . '">' .
                '<button type="button" class="course-ai-mic-button" title="' . s(get_string('speakquestion', 'local_courseaiassistant')) . '"' .
                    ' aria-label="' . s(get_string('speakquestion', 'local_courseaiassistant')) . '">🎤</button>' .
                '<button type="button" class="course-ai-send-button">' . s(get_string('send', 'local_courseaiassistant')) . '</button>' .
            '</div>' .
            '<div class="course-ai-history-panel" aria-hidden="true">' .
                '<div class="course-ai-history-heading"><span>' . s(get_string('conversationhistory', 'local_courseaiassistant')) . '</span>' .
                    '<button type="button" class="course-ai-history-close" aria-label="' . s(get_string('closehistory', 'local_courseaiassistant')) . '">×</button></div>' .
                '<div class="course-ai-history-list"></div>' .
                '<div class="course-ai-history-downloads" aria-live="polite"></div>' .
                '<button type="button" class="course-ai-history-clear-all">' . s(get_string('deleteallconversations', 'local_courseaiassistant')) . '</button>' .
            '</div>' .
            '</div>' .
        '</div>';
    }

    /**
     * Render a compact Dashboard course chooser.
     *
     * @param array<string, mixed> $config
     * @return string
     */
    public static function render_dashboard(array $config): string {
        global $USER;

        $assistantname = (string)($config['assistantname'] ?? get_string('pluginname', 'local_courseaiassistant'));
        $courses = enrol_get_users_courses($USER->id, true, ['id', 'fullname', 'shortname'], 'sortorder ASC, fullname ASC');
        if (empty($courses)) {
            return '<p>' . s(get_string('dashboardnocourses', 'local_courseaiassistant')) . '</p>';
        }

        $items = [];
        foreach ($courses as $course) {
            $coursecontext = \context_course::instance($course->id);
            if (!has_capability('local/courseaiassistant:use', $coursecontext)) {
                continue;
            }
            $url = new \moodle_url('/local/courseaiassistant/assistant.php', ['courseid' => $course->id]);
            $items[] = '<li><a href="' . s($url->out(false)) . '">' . s(format_string($course->fullname)) . '</a></li>';
        }
        if (!$items) {
            return '<p>' . s(get_string('dashboardnocourses', 'local_courseaiassistant')) . '</p>';
        }
        return '<div class="course-ai-dashboard-chooser"><p>' .
            s(get_string('dashboardchoosecourse', 'local_courseaiassistant', $assistantname)) .
            '</p><ul>' . implode('', $items) . '</ul></div>';
    }
}
