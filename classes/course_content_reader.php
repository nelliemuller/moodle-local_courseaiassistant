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
 * Course content reading services.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_courseaiassistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads visible course content and extracts media wherever Moodle stores it.
 */
class course_content_reader {

    /** @var \stdClass */
    private $course;

    /** @var \context_course */
    private $coursecontext;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext = \context_course::instance($course->id);
    }

    /**
     * Format HTML, return plain text, and extract linked or embedded media.
     *
     * @param string $content
     * @param int $format
     * @param \context $context
     * @param string $source
     * @return array
     */
    public function read_html(
        string $content,
        int $format,
        \context $context,
        string $source = ''
    ): array {
        if (trim($content) === '') {
            return ['text' => '', 'media' => []];
        }

        $html = format_text($content, $format, [
            'context' => $context,
            'filter' => true,
            'noclean' => false,
        ]);

        return [
            'text' => $this->plain_text($html),
            'media' => $this->extract_media($html, $source),
        ];
    }

    /**
     * Read visible discussions and posts from a forum.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $forum
     * @return array
     */
    public function read_forum(\cm_info $cm, ?\stdClass $forum): array {
        global $DB, $USER;

        if (!$forum) {
            return [];
        }

        $context = \context_module::instance($cm->id);
        if (!has_capability('mod/forum:viewdiscussion', $context)) {
            return [];
        }

        $params = ['forumid' => (int)$forum->id];
        $groupsql = '';
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $groupids = array_keys(groups_get_all_groups($this->course->id, $USER->id, $cm->groupingid));
            $groupids[] = 0;
            [$insql, $inparams] = $DB->get_in_or_equal(array_values(array_unique($groupids)), SQL_PARAMS_NAMED, 'forumgroup');
            $groupsql = ' AND d.groupid ' . $insql;
            $params += $inparams;
        }

        $discussions = $DB->get_records_sql(
            "SELECT d.id, d.name, d.firstpost, d.groupid, d.timemodified
               FROM {forum_discussions} d
              WHERE d.forum = :forumid {$groupsql}
           ORDER BY d.timemodified DESC",
            $params,
            0,
            100
        );

        $children = [];
        foreach ($discussions as $discussion) {
            // For the course-wide map, keep discussion metadata and the opening post only.
            // Full replies are loaded only when a question targets a specific discussion/post.
            $root = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], 'id,subject,message,messageformat,created,modified,parent');
            $text = '';
            $media = [];
            if ($root) {
                $read = $this->read_html((string)$root->message, (int)$root->messageformat, $context, 'forum_discussion_opening_post');
                $text = $read['text'];
                $media = $read['media'];
            }
            $children[] = [
                'kind' => 'discussion',
                'id' => (int)$discussion->id,
                'name' => format_string((string)$discussion->name, true, ['context' => $context]),
                'text' => $text,
                'media' => $this->unique_media($media),
                'posts' => [],
                'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussion->id]))->out(false),
                'modified' => (int)$discussion->timemodified,
            ];
        }
        return $children;
    }

    /**
     * Read one exact forum discussion, including visible replies.
     * Used only when the current page or user question identifies that discussion.
     */
    public function read_discussion(\cm_info $cm, ?\stdClass $forum, int $discussionid): ?array {
        global $DB, $USER;

        if (!$forum || $discussionid <= 0) {
            return null;
        }
        $context = \context_module::instance($cm->id);
        if (!has_capability('mod/forum:viewdiscussion', $context)) {
            return null;
        }

        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid, 'forum' => $forum->id]);
        if (!$discussion) {
            return null;
        }
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $allowed = array_keys(groups_get_all_groups($this->course->id, $USER->id, $cm->groupingid));
            if ((int)$discussion->groupid !== 0 && !in_array((int)$discussion->groupid, array_map('intval', $allowed), true)) {
                return null;
            }
        }

        $posts = $DB->get_records('forum_posts', ['discussion' => $discussionid], 'created ASC', 'id,userid,subject,message,messageformat,created,modified,parent');
        $postitems = [];
        $alltext = [];
        $allmedia = [];
        foreach ($posts as $post) {
            $read = $this->read_html((string)$post->message, (int)$post->messageformat, $context, 'forum_post');
            $author = '';
            if (!empty($post->userid)) {
                $user = $DB->get_record('user', ['id' => $post->userid], 'id,firstname,lastname');
                if ($user) {
                    $author = fullname($user);
                }
            }
            $postitems[] = [
                'id' => (int)$post->id,
                'author' => $author,
                'subject' => format_string((string)$post->subject, true, ['context' => $context]),
                'text' => $read['text'],
                'media' => $read['media'],
                'created' => (int)$post->created,
                'modified' => (int)$post->modified,
                'isreply' => !empty($post->parent),
                'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussionid], 'p' . $post->id))->out(false),
            ];
            if ($read['text'] !== '') {
                $alltext[] = $read['text'];
            }
            $allmedia = array_merge($allmedia, $read['media']);
        }

        return [
            'kind' => 'discussion',
            'id' => (int)$discussion->id,
            'name' => format_string((string)$discussion->name, true, ['context' => $context]),
            'text' => trim(implode("\n", $alltext)),
            'media' => $this->unique_media($allmedia),
            'posts' => $postitems,
            'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussionid]))->out(false),
            'modified' => (int)$discussion->timemodified,
        ];
    }

    /**
     * Extract links and embeds without assuming that their location defines meaning.
     *
     * @param string $html
     * @param string $source
     * @return array
     */
    private function extract_media(string $html, string $source): array {
        if (trim($html) === '') {
            return [];
        }

        $media = [];
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//a[@href] | //iframe[@src] | //video[@src] | //source[@src]') as $node) {
            $attribute = $node->hasAttribute('href') ? 'href' : 'src';
            $url = trim((string) $node->getAttribute($attribute));
            if ($url === '') {
                continue;
            }

            $label = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent));
            if ($label === '') {
                $label = trim((string) $node->getAttribute('title'));
            }

            $contexttext = $this->nearby_text($node);
            $media[] = $this->classify_url($url, $label, $contexttext, $source);
        }

        // Catch plain-text URLs that were not turned into anchor elements.
        if (preg_match_all('~https?://[^\s<>"\']+~iu', html_entity_decode($html), $matches)) {
            foreach ($matches[0] as $url) {
                $media[] = $this->classify_url(rtrim($url, '.,);]'), '', $this->plain_text($html), $source);
            }
        }

        return $this->unique_media($media);
    }

    private function classify_url(string $url, string $label, string $contexttext, string $source): array {
        $decodedurl = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $host = \core_text::strtolower((string) parse_url($decodedurl, PHP_URL_HOST));
        $path = \core_text::strtolower((string) parse_url($decodedurl, PHP_URL_PATH));
        $combined = \core_text::strtolower(trim($label . ' ' . $contexttext));

        $provider = 'web';
        $kind = 'link';

        if (
            preg_match('/(^|\.)youtube\.com$/u', $host) ||
            preg_match('/(^|\.)youtu\.be$/u', $host) ||
            preg_match('/(^|\.)youtube-nocookie\.com$/u', $host)
        ) {
            $provider = 'youtube';
            $kind = 'video';
        } else if (preg_match('/(^|\.)vimeo\.com$/u', $host)) {
            $provider = 'vimeo';
            $kind = 'video';
        } else if (preg_match('/\.(mp4|webm|m4v|mov|avi)$/u', $path)) {
            $provider = 'video_file';
            $kind = 'video';
        } else if (preg_match('/\.(mp3|wav|m4a|aac|ogg|oga)$/u', $path)) {
            $provider = 'audio_file';
            $kind = 'audio';
        } else if (preg_match('/\.(pdf)$/u', $path)) {
            $provider = 'document_file';
            $kind = 'pdf';
        } else if (preg_match('/\.(doc|docx|odt|rtf|txt)$/u', $path)) {
            $provider = 'document_file';
            $kind = 'document';
        } else if (preg_match('/\.(ppt|pptx|odp)$/u', $path)) {
            $provider = 'presentation_file';
            $kind = 'presentation';
        } else if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/u', $path)) {
            $provider = 'image_file';
            $kind = 'image';
        } else if (preg_match('/(^|\.)drive\.google\.com$/u', $host)) {
            $provider = 'google_drive';
            $kind = 'cloud_file';
        } else if (preg_match('/(^|\.)docs\.google\.com$/u', $host)) {
            $provider = 'google_docs';
            $kind = strpos($path, '/presentation/') !== false ? 'presentation' : (strpos($path, '/spreadsheets/') !== false ? 'spreadsheet' : 'document');
        } else if (preg_match('/(^|\.)(dropbox\.com|onedrive\.live\.com|1drv\.ms)$/u', $host)) {
            $provider = 'cloud_storage';
            $kind = 'cloud_file';
        } else if (preg_match('/(^|\.)zoom\.us$/u', $host) && strpos($path, '/rec/') !== false) {
            $provider = 'zoom_recording';
            $kind = 'video';
        }

        $purpose = 'resource';
        if (preg_match('/\b(recording|recorded|replay|watch the session|session video|webinar recording)\b/u', $combined)) {
            $purpose = 'recording';
        } else if (preg_match('/\b(tutorial|how to|walkthrough|demonstration|demo)\b/u', $combined)) {
            $purpose = 'tutorial';
        } else if (preg_match('/\b(introduction|welcome|orientation)\b/u', $combined)) {
            $purpose = 'introduction';
        } else if (preg_match('/\b(lecture|presentation|keynote|session)\b/u', $combined)) {
            $purpose = 'presentation';
        }

        return [
            'url' => clean_param($decodedurl, PARAM_URL),
            'label' => $label,
            'provider' => $provider,
            'kind' => $kind,
            'purpose' => $purpose,
            'context' => $this->limit($contexttext, 500),
            'source' => $source,
        ];
    }

    private function nearby_text(\DOMNode $node): string {
        $parts = [];
        $current = $node;
        for ($i = 0; $i < 3 && $current; $i++) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $current->textContent));
            if ($text !== '') {
                $parts[] = $text;
            }
            $current = $current->parentNode;
        }
        return $this->limit(implode(' ', array_unique($parts)), 800);
    }

    private function plain_text(string $html): string {
        return trim(preg_replace('/\s+/u', ' ', html_to_text($html, 0, false)));
    }

    private function unique_media(array $media): array {
        $unique = [];
        foreach ($media as $item) {
            $url = (string) ($item['url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (!isset($unique[$url])) {
                $unique[$url] = $item;
                continue;
            }
            // Keep the more meaningful classification when the same URL appears again.
            if (($unique[$url]['purpose'] ?? 'resource') === 'resource' && ($item['purpose'] ?? 'resource') !== 'resource') {
                $unique[$url] = $item;
            }
        }
        return array_values($unique);
    }

    private function limit(string $text, int $length): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (\core_text::strlen($text) <= $length) {
            return $text;
        }
        return \core_text::substr($text, 0, $length) . '…';
    }
}
