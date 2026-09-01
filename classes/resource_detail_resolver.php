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
 * Deep, permission-aware resolver for Moodle core resources.
 *
 * This supplements the universal activity/content resolvers with
 * module-specific child content which is not stored directly in
 * the main activity instance record.
 *
 * Read only.
 *
 * @package local_courseaiassistant
 */
final class resource_detail_resolver {

    /** @var \stdClass */
    private \stdClass $course;

    /**
     * @param \stdClass $course
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
    }

    /**
     * Resolve supported core Moodle resources.
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

        $result = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm instanceof \cm_info) {
                continue;
            }

            if (
                !in_array(
                    $cm->modname,
                    [
                        'book',
                        'page',
                        'url',
                        'label',
                        'resource',
                        'folder',
                    ],
                    true
                )
            ) {
                continue;
            }

            $context =
                \context_module::instance((int)$cm->id);

            $canviewhidden =
                has_capability(
                    'moodle/course:viewhiddenactivities',
                    $context
                );

            if (!$cm->uservisible && !$canviewhidden) {
                continue;
            }

            $details =
                $this->resolve_cm(
                    $cm,
                    $context
                );

            if ($details !== null) {
                $result[] = $details;
            }
        }

        return $result;
    }

    /**
     * Resolve one supported module.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>|null
     */
    private function resolve_cm(
        \cm_info $cm,
        \context_module $context
    ): ?array {
        $base = [
            'cmid' =>
                (int)$cm->id,

            'instanceid' =>
                (int)$cm->instance,

            'module' =>
                (string)$cm->modname,

            'name' =>
                format_string(
                    (string)$cm->name,
                    true,
                    ['context' => $context]
                ),

            'url' =>
                $cm->url
                    ? $cm->url->out(false)
                    : '',
        ];

        switch ($cm->modname) {
            case 'book':
                return array_merge(
                    $base,
                    $this->read_book(
                        $cm,
                        $context
                    )
                );

            case 'resource':
                return array_merge(
                    $base,
                    $this->read_resource_files(
                        $cm,
                        $context
                    )
                );

            case 'folder':
                return array_merge(
                    $base,
                    $this->read_folder(
                        $cm,
                        $context
                    )
                );

            case 'page':
                return array_merge(
                    $base,
                    $this->read_page(
                        $cm,
                        $context
                    )
                );

            case 'url':
                return array_merge(
                    $base,
                    $this->read_url(
                        $cm,
                        $context
                    )
                );

            case 'label':
                return array_merge(
                    $base,
                    $this->read_label(
                        $cm,
                        $context
                    )
                );
        }

        return null;
    }

    /**
     * Read all Book chapters visible to the verified user.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function read_book(
        \cm_info $cm,
        \context_module $context
    ): array {
        global $DB;

        $canviewhidden =
            has_capability(
                'moodle/course:viewhiddenactivities',
                $context
            ) ||
            has_capability(
                'moodle/course:manageactivities',
                $context
            );

        $chapters =
            $DB->get_records(
                'book_chapters',
                ['bookid' => (int)$cm->instance],
                'pagenum ASC, id ASC'
            );

        $items = [];

        foreach ($chapters as $chapter) {
            if (
                !empty($chapter->hidden) &&
                !$canviewhidden
            ) {
                continue;
            }

            $content =
                $this->format_plain_text(
                    (string)($chapter->content ?? ''),
                    (int)($chapter->contentformat ?? FORMAT_HTML),
                    $context
                );

            $items[] = [
                'chapterid' =>
                    (int)$chapter->id,

                'title' =>
                    format_string(
                        (string)$chapter->title,
                        true,
                        ['context' => $context]
                    ),

                'subchapter' =>
                    !empty($chapter->subchapter),

                'hidden' =>
                    !empty($chapter->hidden),

                'pagenum' =>
                    (int)$chapter->pagenum,

                'content' =>
                    $content,

                'url' =>
                    (
                        new \moodle_url(
                            '/mod/book/view.php',
                            [
                                'id' => (int)$cm->id,
                                'chapterid' =>
                                    (int)$chapter->id,
                            ]
                        )
                    )->out(false),

                'files' =>
                    $this->area_files(
                        $context,
                        'mod_book',
                        'chapter',
                        (int)$chapter->id,
                        true
                    ),
            ];
        }

        return [
            'kind' => 'book',
            'chapters' => $items,
        ];
    }

    /**
     * Read a Page resource.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function read_page(
        \cm_info $cm,
        \context_module $context
    ): array {
        global $DB;

        $page =
            $DB->get_record(
                'page',
                ['id' => (int)$cm->instance],
                '*',
                IGNORE_MISSING
            );

        if (!$page) {
            return [
                'kind' => 'page',
                'content' => '',
                'files' => [],
            ];
        }

        return [
            'kind' =>
                'page',

            'content' =>
                $this->format_plain_text(
                    (string)($page->content ?? ''),
                    (int)($page->contentformat ?? FORMAT_HTML),
                    $context
                ),

            'files' =>
                $this->area_files(
                    $context,
                    'mod_page',
                    'content',
                    0,
                    false
                ),
        ];
    }

    /**
     * Read URL resource configuration.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function read_url(
        \cm_info $cm,
        \context_module $context
    ): array {
        global $DB;

        $url =
            $DB->get_record(
                'url',
                ['id' => (int)$cm->instance],
                '*',
                IGNORE_MISSING
            );

        return [
            'kind' =>
                'url',

            'externalurl' =>
                $url
                    ? clean_param(
                        (string)$url->externalurl,
                        PARAM_URL
                    )
                    : '',

            'display' =>
                $url
                    ? (int)$url->display
                    : null,
        ];
    }

    /**
     * Read Text and media area / Label content.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function read_label(
        \cm_info $cm,
        \context_module $context
    ): array {
        global $DB;

        $label =
            $DB->get_record(
                'label',
                ['id' => (int)$cm->instance],
                '*',
                IGNORE_MISSING
            );

        return [
            'kind' =>
                'label',

            'content' =>
                $label
                    ? $this->format_plain_text(
                        (string)($label->intro ?? ''),
                        (int)($label->introformat ?? FORMAT_HTML),
                        $context
                    )
                    : '',
        ];
    }

    /**
     * Read File resource files.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function read_resource_files(
        \cm_info $cm,
        \context_module $context
    ): array {
        return [
            'kind' =>
                'resource',

            'files' =>
                $this->area_files(
                    $context,
                    'mod_resource',
                    'content',
                    0,
                    true
                ),
        ];
    }

    /**
     * Read Folder resource files.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array<string, mixed>
     */
    private function read_folder(
        \cm_info $cm,
        \context_module $context
    ): array {
        return [
            'kind' =>
                'folder',

            'files' =>
                $this->area_files(
                    $context,
                    'mod_folder',
                    'content',
                    0,
                    true
                ),
        ];
    }

    /**
     * Read one Moodle file area.
     *
     * Small text files are read directly.
     * Binary documents remain metadata-only until a dedicated
     * document extractor handles them.
     *
     * @param \context_module $context
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param bool $readtext
     * @return array<int, array<string, mixed>>
     */
    private function area_files(
        \context_module $context,
        string $component,
        string $filearea,
        int $itemid,
        bool $readtext
    ): array {
        $fs =
            get_file_storage();

        try {
            $files =
                $fs->get_area_files(
                    $context->id,
                    $component,
                    $filearea,
                    $itemid,
                    'sortorder, filepath, filename',
                    false
                );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                continue;
            }

            if ($file->is_directory()) {
                continue;
            }

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

            if (
                $readtext &&
                $this->is_text_file($file) &&
                $file->get_filesize() <= 1048576
            ) {
                try {
                    $content =
                        $file->get_content();

                    $entry['textcontent'] =
                        $this->clean_text_file(
                            $content
                        );
                } catch (\Throwable $e) {
                    $entry['textcontent'] = '';
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Determine whether direct textual reading is appropriate.
     *
     * @param \stored_file $file
     * @return bool
     */
    private function is_text_file(
        \stored_file $file
    ): bool {
        $mime =
            strtolower(
                (string)$file->get_mimetype()
            );

        $extension =
            strtolower(
                pathinfo(
                    $file->get_filename(),
                    PATHINFO_EXTENSION
                )
            );

        if (strpos($mime, 'text/') === 0) {
            return true;
        }

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
                'css',
                'js',
                'php',
                'py',
                'java',
                'c',
                'cpp',
                'sql',
                'yaml',
                'yml',
            ],
            true
        );
    }

    /**
     * Clean text-file content before AI use.
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

        /*
         * Keep substantial resource content available while
         * preventing one file from dominating the model request.
         */
        if (mb_strlen($content) > 50000) {
            $content =
                mb_substr(
                    $content,
                    0,
                    50000
                );
        }

        return trim($content);
    }

    /**
     * Format Moodle-authored HTML/text into safe plain text.
     *
     * @param string $value
     * @param int $format
     * @param \context_module $context
     * @return string
     */
    private function format_plain_text(
        string $value,
        int $format,
        \context_module $context
    ): string {
        if (trim($value) === '') {
            return '';
        }

        $formatted =
            format_text(
                $value,
                $format,
                [
                    'context' => $context,
                    'filter' => true,
                    'noclean' => false,
                ]
            );

        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(
                    strip_tags($formatted),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            )
        );
    }
}
