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
 * Builds a permission-aware index of the current Moodle course.
 */
class course_indexer {

    /** @var \stdClass */
    private $course;

    /** @var \course_modinfo */
    private $modinfo;

    /** @var \context_course */
    private $coursecontext;

    /** @var course_content_reader */
    private $contentreader;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->modinfo = get_fast_modinfo($course);
        $this->coursecontext = \context_course::instance($course->id);
        $this->contentreader = new course_content_reader($course);
    }

    /**
     * Return the complete visible course index for the current user.
     *
     * @return array
     */
    public function build(): array {
        $sections = [];
        $items = [];

        foreach ($this->modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) {
                continue;
            }

            $sectioncontent = $this->contentreader->read_html(
                (string)($section->summary ?? ''),
                (int)($section->summaryformat ?? FORMAT_HTML),
                $this->coursecontext,
                'section_summary'
            );

            $sections[(int)$section->section] = [
                'number' => (int)$section->section,
                'name' => get_section_name($this->course, $section),
                'summary' => $sectioncontent['text'],
                'media' => $sectioncontent['media'],
            ];
        }

        foreach ($this->modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }

            $items[] = $this->read_cm($cm, $sections);
        }

        $coursecontent = $this->contentreader->read_html(
            (string)$this->course->summary,
            (int)$this->course->summaryformat,
            $this->coursecontext,
            'course_summary'
        );

        return [
            'course' => [
                'id' => (int)$this->course->id,
                'name' => format_string($this->course->fullname),
                'shortname' => format_string($this->course->shortname),
                'summary' => $coursecontent['text'],
                'media' => $coursecontent['media'],
            ],
            'sections' => array_values($sections),
            'items' => $items,
        ];
    }

    /**
     * Search the visible course index.
     *
     * @param string $question
     * @param array $index
     * @return array
     */
    public function search(string $question, array $index): array {
        $terms = $this->search_terms($question);
        $questionlower = \core_text::strtolower($question);
        $iszoomquery = (bool)preg_match('/\b(zoom|meeting|meetings|session|sessions)\b/u', $questionlower);
        $results = [];

        foreach ($index['items'] as $item) {
            $nametext = \core_text::strtolower(implode(' ', [
                $item['name'] ?? '',
                $item['section'] ?? '',
                $item['type'] ?? '',
                implode(' ', $item['roles'] ?? []),
            ]));
            $instructiontext = \core_text::strtolower((string)($item['description'] ?? ''));

            $resourcetext = [];
            $providertext = [];
            foreach ($item['media'] ?? [] as $medium) {
                $resourcetext[] = implode(' ', [
                    $medium['label'] ?? '',
                    $medium['purpose'] ?? '',
                    $medium['context'] ?? '',
                ]);
                $providertext[] = implode(' ', [
                    $medium['provider'] ?? '',
                    $medium['url'] ?? '',
                ]);
            }
            $resourcetext = \core_text::strtolower(implode(' ', $resourcetext));
            $providertext = \core_text::strtolower(implode(' ', $providertext));

            $discussiontext = [];
            foreach ($item['children'] ?? [] as $child) {
                $discussiontext[] = implode(' ', [
                    $child['name'] ?? '',
                    $child['text'] ?? '',
                ]);
            }
            $discussiontext = \core_text::strtolower(implode(' ', $discussiontext));

            $score = 0;

            if ($iszoomquery && in_array('live_meeting', $item['roles'] ?? [], true)) {
                $score += 80;
            }

            foreach ($terms as $term) {
                // Meaning-bearing Moodle content is much stronger evidence than a
                // provider/domain word embedded in a URL.
                if (\core_text::strpos($nametext, $term) !== false) {
                    $score += 24;
                }
                if (\core_text::strpos($instructiontext, $term) !== false) {
                    $score += 22;
                }
                if (\core_text::strpos($resourcetext, $term) !== false) {
                    $score += 10;
                }
                if (\core_text::strpos($discussiontext, $term) !== false) {
                    $score += 5;
                }
                if (\core_text::strpos($providertext, $term) !== false) {
                    $score += 1;
                }

                foreach (preg_split('/[^\pL\pN]+/u', \core_text::strtolower((string)($item['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                    if (\core_text::strlen($term) >= 5 && levenshtein($term, $token) <= 2) {
                        $score += 5;
                        break;
                    }
                }
            }

            if ($score > 0) {
                $item['score'] = $score;
                $results[] = $item;
            }
        }

        usort($results, static function(array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        return $results;
    }

    /**
     * Get a visible course module by cmid.
     *
     * @param int $cmid
     * @param array $index
     * @return array|null
     */
    public function get_item(int $cmid, array $index): ?array {
        foreach ($index['items'] as $item) {
            if ((int)$item['cmid'] === $cmid) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Resolve one visible forum discussion to its parent activity and full visible posts.
     *
     * @param int $discussionid
     * @param array $index
     * @return array|null
     */
    public function get_discussion(int $discussionid, array $index): ?array {
        global $DB;
        if ($discussionid <= 0) {
            return null;
        }
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], 'id,forum');
        if (!$discussion) {
            return null;
        }
        foreach ($this->modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || $cm->modname !== 'forum' || (int)$cm->instance !== (int)$discussion->forum) {
                continue;
            }
            $forum = $DB->get_record('forum', ['id' => $cm->instance]);
            $full = $this->contentreader->read_discussion($cm, $forum, $discussionid);
            if (!$full) {
                return null;
            }
            return [
                'activity' => $this->get_item((int)$cm->id, $index),
                'discussion' => $full,
            ];
        }
        return null;
    }

    private function read_cm(\cm_info $cm, array $sections): array {
        global $DB;

        $modulecontext = \context_module::instance($cm->id);
        $record = null;
        $cmrecord = $DB->get_record('course_modules', ['id' => $cm->id], 'id,availability,completion,completionview,completionexpected,completionpassgrade,completiongradeitemnumber');
        $table = new \xmldb_table($cm->modname);

        if ($DB->get_manager()->table_exists($table)) {
            $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        }

        $description = '';
        $media = [];
        if ($record) {
            if (property_exists($record, 'intro') && trim((string)$record->intro) !== '') {
                $read = $this->contentreader->read_html(
                    (string)$record->intro,
                    (int)($record->introformat ?? FORMAT_HTML),
                    $modulecontext,
                    'activity_description'
                );
                $description = $read['text'];
                $media = $read['media'];
            } else if (property_exists($record, 'content') && trim((string)$record->content) !== '') {
                $read = $this->contentreader->read_html(
                    (string)$record->content,
                    (int)($record->contentformat ?? FORMAT_HTML),
                    $modulecontext,
                    'activity_content'
                );
                $description = $read['text'];
                $media = $read['media'];
            }
        }

        $externalurl = '';
        if ($record && property_exists($record, 'externalurl')) {
            $externalurl = clean_param((string)$record->externalurl, PARAM_URL);
            if ($externalurl !== '') {
                $externalread = $this->contentreader->read_html(
                    '<a href="' . s($externalurl) . '">' . s((string)$cm->name) . '</a>',
                    FORMAT_HTML,
                    $modulecontext,
                    'external_url'
                );
                $media = array_merge($media, $externalread['media']);
            }
        }

        $children = [];
        if ($cm->modname === 'forum') {
            // Keep discussion content scoped to the discussion. Do not promote links/media
            // from participant posts into the forum's general resource list.
            $children = $this->contentreader->read_forum($cm, $record);
        }

        $dates = [];
        foreach ([
            'timeopen' => 'Opens',
            'timeclose' => 'Closes',
            'allowsubmissionsfromdate' => 'Submissions open',
            'duedate' => 'Due',
            'cutoffdate' => 'Cut-off',
            'startdate' => 'Starts',
            'enddate' => 'Ends',
        ] as $field => $label) {
            if ($record && !empty($record->{$field})) {
                $dates[] = $label . ': ' . userdate((int)$record->{$field});
            }
        }

        $typename = $cm->modname;
        $component = 'mod_' . $cm->modname;
        if (get_string_manager()->string_exists('modulename', $component)) {
            $typename = get_string('modulename', $component);
        }

        return [
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$cm->instance,
            'name' => format_string($cm->name),
            'modname' => $cm->modname,
            'type' => $typename,
            'sectionnum' => (int)$cm->sectionnum,
            'section' => $sections[(int)$cm->sectionnum]['name'] ?? '',
            'description' => $description,
            'url' => !empty($cm->url) ? $cm->url->out(false) : '',
            'externalurl' => $externalurl,
            'dates' => $dates,
            'media' => $this->unique_media($media),
            'children' => $children,
            'completion' => [
                'tracking' => (int)($cmrecord->completion ?? 0),
                'viewrequired' => !empty($cmrecord->completionview),
                'passgraderequired' => !empty($cmrecord->completionpassgrade),
                'gradeitemnumber' => isset($cmrecord->completiongradeitemnumber) ? (int)$cmrecord->completiongradeitemnumber : null,
                'expected' => (int)($cmrecord->completionexpected ?? 0),
                'customrules' => $this->completion_rules($record),
            ],
            'restrictions' => [
                'available' => (bool)$cm->available,
                'uservisible' => (bool)$cm->uservisible,
                'availabilityjson' => (string)($cmrecord->availability ?? ''),
            ],
            'roles' => $this->classify_item_roles($cm, $description, $media, $children),
        ];
    }

    /**
     * Capture module-specific completion settings without hard-coding one Moodle activity type.
     * Known fields get human labels; unknown non-empty completion* fields are still retained so
     * the reasoning layer can understand custom module completion requirements.
     *
     * @param \stdClass|null $record
     * @return array<int, array<string, mixed>>
     */
    private function completion_rules(?\stdClass $record): array {
        if (!$record) {
            return [];
        }

        $labels = [
            'completiondiscussions' => 'Required discussions',
            'completionreplies' => 'Required replies',
            'completionposts' => 'Required posts',
            'completionsubmit' => 'Submission required',
            'completionattemptsexhausted' => 'All attempts must be used',
            'completionminattempts' => 'Minimum attempts',
            'completionusegrade' => 'Grade required',
            'completionpassgrade' => 'Passing grade required',
            'completionview' => 'View required',
        ];
        $rules = [];
        foreach (get_object_vars($record) as $field => $value) {
            if (strpos($field, 'completion') !== 0 || $value === null || $value === '' || $value === 0 || $value === '0') {
                continue;
            }
            if (in_array($field, ['completion', 'completionexpected'], true)) {
                continue;
            }
            $rules[] = [
                'field' => $field,
                'label' => $labels[$field] ?? preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('_', ' ', $field)),
                'value' => is_scalar($value) ? $value : json_encode($value),
            ];
        }
        return $rules;
    }

    private function unique_media(array $media): array {
        $unique = [];
        foreach ($media as $item) {
            $url = (string)($item['url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (!isset($unique[$url])) {
                $unique[$url] = $item;
            } else if (($unique[$url]['purpose'] ?? 'resource') === 'resource' && ($item['purpose'] ?? 'resource') !== 'resource') {
                $unique[$url] = $item;
            }
        }
        return array_values($unique);
    }

    private function classify_item_roles(\cm_info $cm, string $description, array $media, array $children): array {
        $roles = [];
        $modname = (string)$cm->modname;
        $text = \core_text::strtolower(trim($cm->name . ' ' . $description));

        if (in_array($modname, ['zoom', 'bigbluebuttonbn'], true)) {
            $roles[] = 'live_meeting';
        }
        if (in_array($modname, ['assign', 'quiz', 'workshop', 'choice', 'feedback', 'h5pactivity'], true)) {
            $roles[] = 'learner_action';
        }
        if ($modname === 'forum') {
            $roles[] = 'discussion';
        }
        if (!empty($children)) {
            $roles[] = 'container';
        }

        foreach ($media as $medium) {
            if (($medium['kind'] ?? '') === 'video') {
                $roles[] = 'video';
            }
            if (($medium['purpose'] ?? '') === 'recording') {
                $roles[] = 'recording';
            }
            if (($medium['purpose'] ?? '') === 'tutorial') {
                $roles[] = 'tutorial';
            }
        }

        if (preg_match('/\b(welcome|orientation|getting started|start here|overview|introduction|course guide|syllabus)\b/u', $text)) {
            $roles[] = 'orientation';
        }

        return array_values(array_unique($roles));
    }

    private function search_terms(string $question): array {
        $words = preg_split('/[^\pL\pN]+/u', \core_text::strtolower($question), -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = [
            'a', 'an', 'and', 'are', 'about', 'activity', 'activities', 'available',
            'can', 'course', 'do', 'does', 'for', 'give', 'how', 'i', 'in', 'is',
            'it', 'link', 'links', 'list', 'me', 'my', 'of', 'on', 'please', 'show',
            'the', 'their', 'there', 'to', 'what', 'where', 'which', 'with', 'all',
        ];

        return array_values(array_unique(array_filter($words, static function(string $word) use ($stopwords): bool {
            return \core_text::strlen($word) >= 3 && !in_array($word, $stopwords, true);
        })));
    }
}
