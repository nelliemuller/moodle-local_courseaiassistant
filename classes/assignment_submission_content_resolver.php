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
 * Deep, permission-aware Assignment submission content.
 *
 * Uses Moodle's Assignment submission plugin interfaces rather than
 * assuming that all submissions are core online text or files.
 *
 * Supports:
 *
 * - core and third-party assignsubmission_* plugins
 * - editor fields exposed by submission plugins
 * - plugin-rendered submission content
 * - submission files exposed through plugin APIs
 * - exact participant grader destinations
 *
 * Read only.
 *
 * @package local_courseaiassistant
 */
final class assignment_submission_content_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $coursecontext;

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;

        $this->coursecontext =
            \context_course::instance(
                (int)$course->id
            );
    }

    /**
     * Resolve submission content only when the request needs it.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return array<string, mixed>
     */
    public function resolve(
        string $question,
        array $history = []
    ): array {
        if (
            !$this->request_needs_submission_content(
                $question,
                $history
            )
        ) {
            return [
                'active' =>
                    false,

                'assignments' =>
                    [],
            ];
        }

        if (!$this->can_inspect_assignments()) {
            return [
                'active' =>
                    false,

                'reason' =>
                    'insufficient_permission',

                'assignments' =>
                    [],
            ];
        }

        global $CFG, $USER;

        require_once(
            $CFG->dirroot .
            '/mod/assign/locallib.php'
        );

        $modinfo =
            get_fast_modinfo(
                $this->course,
                $USER->id
            );

        $assignments = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (
                !$cm instanceof \cm_info ||
                $cm->modname !== 'assign'
            ) {
                continue;
            }

            $context =
                \context_module::instance(
                    (int)$cm->id
                );

            if (
                !$cm->uservisible &&
                !has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                )
            ) {
                continue;
            }

            if (
                !has_capability(
                    'mod/assign:grade',
                    $context
                )
            ) {
                continue;
            }

            $resolved =
                $this->assignment_content(
                    $cm,
                    $context,
                    $question
                );

            if ($resolved !== null) {
                $assignments[] =
                    $resolved;
            }
        }

        return [
            'active' =>
                true,

            'assignments' =>
                $assignments,
        ];
    }

    /**
     * Determine whether deep submission content is actually relevant.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @return bool
     */
    private function request_needs_submission_content(
        string $question,
        array $history
    ): bool {
        $text =
            \core_text::strtolower(
                trim(
                    $question . ' ' .
                    $this->history_text(
                        $history
                    )
                )
            );

        if ($text === '') {
            return false;
        }

        return (bool)preg_match(
            '/\b(' .
            'assignment|assignments|' .
            'submission|submissions|submitted|' .
            'what did .* submit|' .
            'what has .* submitted|' .
            'submitted file|submitted files|' .
            'online text|' .
            'attachment|attachments|' .
            'review .* work|' .
            'read .* submission|' .
            'summarize .* submission|' .
            'grade|grading|needs grading|ready for review|' .
            'revise|revision|resubmit|resubmission' .
            ')\b/u',
            $text
        );
    }

    /**
     * Preserve recent conversational context.
     *
     * @param array<int, mixed> $history
     * @return string
     */
    private function history_text(
        array $history
    ): string {
        $parts = [];

        foreach (
            array_slice(
                $history,
                -4
            ) as $entry
        ) {
            if (is_string($entry)) {
                $parts[] =
                    $entry;

                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            foreach (
                [
                    'message',
                    'content',
                    'text',
                    'question',
                    'answer',
                ] as $key
            ) {
                if (
                    isset($entry[$key]) &&
                    is_string($entry[$key])
                ) {
                    $parts[] =
                        $entry[$key];
                }
            }
        }

        return implode(
            ' ',
            $parts
        );
    }

    /**
     * Course-level authority.
     *
     * Module-level assign:grade is checked separately.
     *
     * @return bool
     */
    private function can_inspect_assignments(): bool {
        return
            is_siteadmin() ||
            (
                has_capability(
                    'moodle/course:viewparticipants',
                    $this->coursecontext
                ) &&
                (
                    has_capability(
                        'moodle/grade:viewall',
                        $this->coursecontext
                    ) ||
                    has_capability(
                        'moodle/grade:edit',
                        $this->coursecontext
                    ) ||
                    has_capability(
                        'moodle/course:manageactivities',
                        $this->coursecontext
                    )
                )
            );
    }

    /**
     * Deep content for one Assignment activity.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param string $question
     * @return array<string, mixed>|null
     */
    private function assignment_content(
        \cm_info $cm,
        \context_module $context,
        string $question
    ): ?array {
        global $DB;

        try {
            $assignment =
                new \assign(
                    $context,
                    $cm,
                    $this->course
                );
        } catch (\Throwable $e) {
            return null;
        }

        $userids =
            $this->submission_userids(
                $cm,
                $question
            );

        if (!$userids) {
            return [
                'cmid' =>
                    (int)$cm->id,

                'assignmentid' =>
                    (int)$cm->instance,

                'name' =>
                    format_string(
                        (string)$cm->name,
                        true,
                        [
                            'context' =>
                                $context,
                        ]
                    ),

                'url' =>
                    $cm->url
                        ? $cm->url->out(false)
                        : '',

                'submissionplugins' =>
                    $this->plugin_inventory(
                        $assignment
                    ),

                'submissions' =>
                    [],
            ];
        }

        $submissions = [];

        foreach ($userids as $userid) {
            $user =
                $DB->get_record(
                    'user',
                    [
                        'id' =>
                            $userid,
                    ],
                    'id,firstname,lastname',
                    IGNORE_MISSING
                );

            if (!$user) {
                continue;
            }

            /*
             * Use Moodle's own Assignment permission check for this
             * particular participant before inspecting the submission.
             */
            try {
                $assignment->require_view_submission(
                    $userid
                );
            } catch (\Throwable $e) {
                continue;
            }

            /*
             * false = do not create a submission record.
             * -1 = latest attempt.
             */
            try {
                $submission =
                    $assignment->get_user_submission(
                        $userid,
                        false,
                        -1
                    );
            } catch (\Throwable $e) {
                $submission = false;
            }

            if (!$submission) {
                continue;
            }

            $plugincontent =
                $this->submission_plugin_content(
                    $assignment,
                    $submission,
                    $user
                );

            $submissions[] = [
                'userid' =>
                    $userid,

                'participant' =>
                    fullname($user),

                'submissionid' =>
                    (int)$submission->id,

                'status' =>
                    (string)(
                        $submission->status
                        ?? ''
                    ),

                'attemptnumber' =>
                    (int)(
                        $submission->attemptnumber
                        ?? 0
                    ),

                'timecreated' =>
                    (int)(
                        $submission->timecreated
                        ?? 0
                    ),

                'timemodified' =>
                    (int)(
                        $submission->timemodified
                        ?? 0
                    ),

                'plugins' =>
                    $plugincontent,

                'graderurl' =>
                    (
                        new \moodle_url(
                            '/mod/assign/view.php',
                            [
                                'id' =>
                                    (int)$cm->id,

                                'action' =>
                                    'grader',

                                'userid' =>
                                    $userid,
                            ]
                        )
                    )->out(false),
            ];
        }

        return [
            'cmid' =>
                (int)$cm->id,

            'assignmentid' =>
                (int)$cm->instance,

            'name' =>
                format_string(
                    (string)$cm->name,
                    true,
                    [
                        'context' =>
                            $context,
                    ]
                ),

            'url' =>
                $cm->url
                    ? $cm->url->out(false)
                    : '',

            'submissionplugins' =>
                $this->plugin_inventory(
                    $assignment
                ),

            'submissions' =>
                $submissions,
        ];
    }

    /**
     * Find users with actual Assignment submission records.
     *
     * If a participant's full name appears in the question, narrow
     * evidence to that participant.
     *
     * @param \cm_info $cm
     * @param string $question
     * @return int[]
     */
    private function submission_userids(
        \cm_info $cm,
        string $question
    ): array {
        global $DB;

        $rows =
            $DB->get_records(
                'assign_submission',
                [
                    'assignment' =>
                        (int)$cm->instance,

                    'latest' =>
                        1,
                ],
                'userid ASC',
                'id,userid'
            );

        $userids = [];

        foreach ($rows as $row) {
            $userid =
                (int)$row->userid;

            if ($userid > 0) {
                $userids[$userid] =
                    $userid;
            }
        }

        if (!$userids) {
            return [];
        }

        $questionlower =
            \core_text::strtolower(
                $question
            );

        $named = [];

        foreach ($userids as $userid) {
            $user =
                $DB->get_record(
                    'user',
                    [
                        'id' =>
                            $userid,
                    ],
                    'id,firstname,lastname',
                    IGNORE_MISSING
                );

            if (!$user) {
                continue;
            }

            $fullname =
                \core_text::strtolower(
                    fullname($user)
                );

            if (
                $fullname !== '' &&
                \core_text::strpos(
                    $questionlower,
                    $fullname
                ) !== false
            ) {
                $named[$userid] =
                    $userid;
            }
        }

        if ($named) {
            return array_values(
                $named
            );
        }

        /*
         * Prevent one large course from dumping hundreds of full
         * submissions into a single model request.
         *
         * The question can be narrowed by participant/activity when
         * more evidence is needed.
         */
        return array_slice(
            array_values($userids),
            0,
            100
        );
    }

    /**
     * Installed/enabled Assignment submission plugin inventory.
     *
     * @param \assign $assignment
     * @return array<int, array<string, mixed>>
     */
    private function plugin_inventory(
        \assign $assignment
    ): array {
        $result = [];

        try {
            $plugins =
                $assignment->get_submission_plugins();
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($plugins as $plugin) {
            if (
                !$plugin instanceof
                    \assign_submission_plugin
            ) {
                continue;
            }

            $enabled = false;

            try {
                $enabled =
                    (bool)$plugin->is_enabled();
            } catch (\Throwable $e) {
                $enabled = false;
            }

            $result[] = [
                'type' =>
                    $this->plugin_type(
                        $plugin
                    ),

                'name' =>
                    $this->plugin_name(
                        $plugin
                    ),

                'enabled' =>
                    $enabled,

                'visible' =>
                    $this->plugin_visible(
                        $plugin
                    ),

                'allowsubmissions' =>
                    $this->plugin_allows_submissions(
                        $plugin
                    ),
            ];
        }

        return $result;
    }

    /**
     * Read submission content through each enabled submission plugin.
     *
     * @param \assign $assignment
     * @param \stdClass $submission
     * @param \stdClass $user
     * @return array<int, array<string, mixed>>
     */
    private function submission_plugin_content(
        \assign $assignment,
        \stdClass $submission,
        \stdClass $user
    ): array {
        try {
            $plugins =
                $assignment->get_submission_plugins();
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];

        foreach ($plugins as $plugin) {
            if (
                !$plugin instanceof
                    \assign_submission_plugin
            ) {
                continue;
            }

            try {
                if (!$plugin->is_enabled()) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }

            $type =
                $this->plugin_type(
                    $plugin
                );

            $entry = [
                'type' =>
                    $type,

                'name' =>
                    $this->plugin_name(
                        $plugin
                    ),

                'editorfields' =>
                    [],

                'renderedcontent' =>
                    '',

                'files' =>
                    [],
            ];

            /*
             * Standard Assignment submission plugins can expose
             * editor fields without the Course Assistant needing to
             * know the plugin's private database schema.
             */
            if (
                method_exists(
                    $plugin,
                    'get_editor_fields'
                )
            ) {
                try {
                    $fields =
                        $plugin->get_editor_fields();

                    if (is_array($fields)) {
                        foreach (
                            $fields as
                            $fieldname => $label
                        ) {
                            try {
                                $text =
                                    $plugin->get_editor_text(
                                        $fieldname,
                                        (int)$submission->id
                                    );
                            } catch (\Throwable $e) {
                                $text = '';
                            }

                            if (
                                is_string($text) &&
                                trim($text) !== ''
                            ) {
                                $entry['editorfields'][] = [
                                    'field' =>
                                        (string)$fieldname,

                                    'label' =>
                                        is_string($label)
                                            ? $label
                                            : (string)$fieldname,

                                    'text' =>
                                        $this->plain_submission_text(
                                            $text
                                        ),
                                ];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Continue with other standard plugin interfaces.
                }
            }

            /*
             * view() is part of the standard Assignment plugin
             * interface and can expose plugin-specific submitted
             * content without guessing its private tables.
             */
            try {
                $view =
                    $plugin->view(
                        $submission
                    );

                if (
                    is_string($view) &&
                    trim($view) !== ''
                ) {
                    $entry['renderedcontent'] =
                        $this->plain_submission_text(
                            $view
                        );
                }
            } catch (\Throwable $e) {
                $entry['renderedcontent'] = '';
            }

            /*
             * get_files() is also part of Moodle's Assignment plugin
             * contract. Student-submitted files remain untrusted;
             * we inspect metadata and only directly read small,
             * clearly textual files.
             */
            try {
                $files =
                    $plugin->get_files(
                        $submission,
                        $user
                    );

                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (!$file instanceof \stored_file) {
                            continue;
                        }

                        $entry['files'][] =
                            $this->file_evidence(
                                $file
                            );
                    }
                }
            } catch (\Throwable $e) {
                $entry['files'] = [];
            }

            /*
             * Do not add an empty plugin entry merely because the
             * plugin happens to be installed.
             */
            if (
                !$entry['editorfields'] &&
                $entry['renderedcontent'] === '' &&
                !$entry['files']
            ) {
                continue;
            }

            $result[] =
                $entry;
        }

        return $result;
    }

    /**
     * Safe evidence for one submitted file.
     *
     * @param \stored_file $file
     * @return array<string, mixed>
     */
    private function file_evidence(
        \stored_file $file
    ): array {
        $entry = [
            'filename' =>
                $file->get_filename(),

            'filepath' =>
                $file->get_filepath(),

            'mimetype' =>
                (string)$file->get_mimetype(),

            'filesize' =>
                (int)$file->get_filesize(),

            'modified' =>
                (int)$file->get_timemodified(),

            'contenthash' =>
                (string)$file->get_contenthash(),

            'textcontent' =>
                '',
        ];

        /*
         * Do not directly read binary submissions.
         *
         * Small textual files are safe to represent as text evidence.
         */
        if (
            $file->get_filesize() <= 1048576 &&
            $this->is_text_file(
                $file
            )
        ) {
            try {
                $entry['textcontent'] =
                    $this->clean_text_file(
                        $file->get_content()
                    );
            } catch (\Throwable $e) {
                $entry['textcontent'] = '';
            }
        }

        return $entry;
    }

    /**
     * Is direct text reading suitable?
     *
     * @param \stored_file $file
     * @return bool
     */
    private function is_text_file(
        \stored_file $file
    ): bool {
        $mime =
            \core_text::strtolower(
                (string)$file->get_mimetype()
            );

        if (
            \core_text::strpos(
                $mime,
                'text/'
            ) === 0
        ) {
            return true;
        }

        $extension =
            \core_text::strtolower(
                pathinfo(
                    $file->get_filename(),
                    PATHINFO_EXTENSION
                )
            );

        return in_array(
            $extension,
            [
                'txt',
                'md',
                'markdown',
                'csv',
                'tsv',
                'json',
                'xml',
                'html',
                'htm',
                'yaml',
                'yml',
                'sql',
                'py',
                'php',
                'js',
                'css',
                'java',
                'c',
                'cpp',
            ],
            true
        );
    }

    /**
     * Clean textual submitted file.
     *
     * @param string $content
     * @return string
     */
    private function clean_text_file(
        string $content
    ): string {
        $content =
            str_replace(
                "\0",
                '',
                $content
            );

        if (
            !mb_check_encoding(
                $content,
                'UTF-8'
            )
        ) {
            $content =
                mb_convert_encoding(
                    $content,
                    'UTF-8',
                    'UTF-8, ISO-8859-1, Windows-1252'
                );
        }

        if (
            mb_strlen(
                $content
            ) > 50000
        ) {
            $content =
                mb_substr(
                    $content,
                    0,
                    50000
                );
        }

        return trim(
            $content
        );
    }

    /**
     * Convert plugin-rendered content to safe plain text.
     *
     * @param string $value
     * @return string
     */
    private function plain_submission_text(
        string $value
    ): string {
        $plain =
            trim(
                html_to_text(
                    $value,
                    0,
                    false
                )
            );

        if (
            \core_text::strlen(
                $plain
            ) > 50000
        ) {
            $plain =
                \core_text::substr(
                    $plain,
                    0,
                    50000
                );
        }

        return $plain;
    }

    /**
     * Submission plugin type.
     *
     * @param \assign_submission_plugin $plugin
     * @return string
     */
    private function plugin_type(
        \assign_submission_plugin $plugin
    ): string {
        try {
            if (
                method_exists(
                    $plugin,
                    'get_type'
                )
            ) {
                return
                    (string)$plugin->get_type();
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        $class =
            get_class(
                $plugin
            );

        return preg_replace(
            '/^assign_submission_/',
            '',
            $class
        );
    }

    /**
     * Human-readable submission plugin name.
     *
     * @param \assign_submission_plugin $plugin
     * @return string
     */
    private function plugin_name(
        \assign_submission_plugin $plugin
    ): string {
        try {
            return trim(
                (string)$plugin->get_name()
            );
        } catch (\Throwable $e) {
            return $this->plugin_type(
                $plugin
            );
        }
    }

    /**
     * Plugin visibility.
     *
     * @param \assign_submission_plugin $plugin
     * @return bool|null
     */
    private function plugin_visible(
        \assign_submission_plugin $plugin
    ): ?bool {
        try {
            return
                (bool)$plugin->is_visible();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the plugin represents student-submitted work.
     *
     * @param \assign_submission_plugin $plugin
     * @return bool|null
     */
    private function plugin_allows_submissions(
        \assign_submission_plugin $plugin
    ): ?bool {
        try {
            return
                (bool)$plugin->allow_submissions();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
