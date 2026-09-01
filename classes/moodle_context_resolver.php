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
 * Deep, permission-aware Moodle context resolution.
 *
 * The broad course index tells the assistant what exists.
 * This resolver determines which Moodle entities matter NOW and
 * expands live teaching/work evidence without dumping the entire
 * course into every model request.
 *
 * Read only. It never mutates Moodle.
 */
final class moodle_context_resolver {

    /** @var array<string, mixed> */
    private array $index;

    /** @var array<string, mixed> */
    private array $agentcontext;

    /**
     * @param array<string, mixed> $index
     * @param array<string, mixed> $agentcontext
     */
    public function __construct(
        array $index,
        array $agentcontext
    ) {
        $this->index = $index;
        $this->agentcontext = $agentcontext;
    }

    /**
     * Resolve deep context for one conversational request.
     *
     * @param string $question
     * @param array<int, mixed> $history
     * @param array<string, mixed> $pagecontext
     * @return array<string, mixed>
     */
    public function resolve(
        string $question,
        array $history = [],
        array $pagecontext = []
    ): array {

        $taskstate =
            $this->task_state(
                $question,
                $history,
                $pagecontext
            );

        return [
            /*
             * What kind of Moodle task is being continued?
             */
            'taskstate' =>
                $taskstate,

            /*
             * Relevant course entities expanded from the complete
             * course index rather than a fixed first-N search slice.
             */
            'relevantentities' =>
                $this->relevant_entities(
                    $question,
                    $history,
                    $pagecontext
                ),

            /*
             * Live teacher work comes from authoritative Moodle
             * activity evidence, not from prose or model guesses.
             */
            'teacherwork' =>
                $this->teacher_work(),

            /*
             * Flattened resource awareness across the course.
             */
            'resources' =>
                $this->resource_catalog(),

            /*
             * Compact inventory lets the model reason about the
             * whole course without losing structural awareness.
             */
            'inventory' =>
                $this->inventory(),
        ];
    }

    /**
     * Preserve conversational task/entity continuity.
     *
     * @return array<string, mixed>
     */
    private function task_state(
        string $question,
        array $history,
        array $pagecontext
    ): array {

        $handledurls = [];
        $recentcontexts = [];
        $lastuser = '';
        $lastassistant = '';

        foreach (array_slice($history, -16) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $role =
                (string)($entry['role'] ?? '');

            $content =
                trim(
                    (string)($entry['content'] ?? '')
                );

            if ($role === 'user' && $content !== '') {
                $lastuser = $content;
            }

            if ($role === 'assistant' && $content !== '') {
                $lastassistant = $content;

                foreach (
                    $this->extract_urls($content)
                    as $url
                ) {
                    $handledurls[$url] = $url;
                }
            }

            $context =
                is_array($entry['context'] ?? null)
                    ? $entry['context']
                    : [];

            if ($context) {
                $recentcontexts[] = $context;

                foreach (
                    $this->urls_from_array($context)
                    as $url
                ) {
                    $handledurls[$url] = $url;
                }
            }
        }

        $pageurl =
            trim(
                (string)($pagecontext['pageurl'] ?? '')
            );

        return [
            'request' =>
                $question,

            'intentfamily' =>
                $this->intent_family(
                    $question,
                    $lastuser,
                    $lastassistant
                ),

            'iscontinuation' =>
                $this->looks_like_continuation($question),

            'previoususerrequest' =>
                $lastuser,

            'previousassistantanswer' =>
                $lastassistant,

            'currentpage' => [
                'title' =>
                    (string)($pagecontext['pagetitle'] ?? ''),
                'url' =>
                    $pageurl,
                'identifiers' =>
                    $this->url_identifiers($pageurl),
            ],

            /*
             * URLs already surfaced in the conversation are useful
             * for "other", "next", "who else", and "any more".
             */
            'handledurls' =>
                array_values($handledurls),

            'recententitycontexts' =>
                array_slice($recentcontexts, -8),
        ];
    }

    /**
     * Broad semantic task family.
     */
    private function intent_family(
        string $question,
        string $lastuser,
        string $lastassistant
    ): string {

        $text =
            \core_text::strtolower(
                trim(
                    $question . ' ' .
                    $lastuser . ' ' .
                    $lastassistant
                )
            );

        if (
            preg_match(
                '/\b(reply|replies|respond|response|forum post|forum posts)\b/u',
                $text
            )
        ) {
            return 'forum_response_work';
        }

        if (
            preg_match(
                '/\b(grade|grading|graded|rating|mark|score)\b/u',
                $text
            )
        ) {
            return 'grading_work';
        }

        if (
            preg_match(
                '/\b(complete|completion|finish|finished|done|progress|next|start)\b/u',
                $text
            )
        ) {
            return 'progress';
        }

        if (
            preg_match(
                '/\b(resource|resources|file|files|video|videos|link|links|tool|tools|url)\b/u',
                $text
            )
        ) {
            return 'resource_discovery';
        }

        return 'course_understanding';
    }

    /**
     * Does this wording normally depend on preceding conversation?
     */
    private function looks_like_continuation(
        string $question
    ): bool {

        $q =
            \core_text::strtolower(
                trim($question)
            );

        return (bool)preg_match(
            '/\b(other|another|else|more|next|that|those|them|her|his|their|it|same|remaining|rest)\b/u',
            $q
        );
    }

    /**
     * Dynamically rank complete indexed Moodle entities.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relevant_entities(
        string $question,
        array $history,
        array $pagecontext
    ): array {

        $terms =
            $this->query_terms(
                $question,
                $history
            );

        $pageurl =
            (string)($pagecontext['pageurl'] ?? '');

        $pageids =
            $this->url_identifiers($pageurl);

        $ranked = [];

        foreach (
            $this->index['items'] ?? []
            as $item
        ) {
            if (!is_array($item)) {
                continue;
            }

            $score = 0;

            $haystack =
                \core_text::strtolower(
                    implode(
                        ' ',
                        [
                            (string)($item['name'] ?? ''),
                            (string)($item['section'] ?? ''),
                            (string)($item['type'] ?? ''),
                            (string)($item['modname'] ?? ''),
                            (string)($item['description'] ?? ''),
                            (string)($item['url'] ?? ''),
                            (string)($item['externalurl'] ?? ''),
                        ]
                    )
                );

            foreach ($terms as $term) {
                if (
                    $term !== '' &&
                    \core_text::strpos(
                        $haystack,
                        $term
                    ) !== false
                ) {
                    $score++;
                }
            }

            $cmid =
                (int)($item['cmid'] ?? 0);

            if (
                $cmid > 0 &&
                $cmid ===
                    (int)($pageids['cmid'] ?? 0)
            ) {
                $score += 100;
            }

            if ($score <= 0) {
                continue;
            }

            $ranked[] = [
                'score' => $score,
                'entity' =>
                    $this->expand_item($item),
            ];
        }

        usort(
            $ranked,
            static function(
                array $a,
                array $b
            ): int {
                return
                    ($b['score'] ?? 0)
                    <=>
                    ($a['score'] ?? 0);
            }
        );

        return array_values(
            array_map(
                static fn(array $row): array =>
                    $row['entity'],
                array_slice($ranked, 0, 24)
            )
        );
    }

    /**
     * Expand a relevant Moodle activity/resource deeply.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function expand_item(
        array $item
    ): array {

        return [
            'cmid' =>
                (int)($item['cmid'] ?? 0),

            'name' =>
                (string)($item['name'] ?? ''),

            'type' =>
                (string)($item['type'] ?? ''),

            'modname' =>
                (string)($item['modname'] ?? ''),

            'section' =>
                (string)($item['section'] ?? ''),

            /*
             * Deep context intentionally preserves the full indexed
             * description rather than the 1000-character overview.
             */
            'instructions' =>
                trim(
                    (string)($item['description'] ?? '')
                ),

            'completion' =>
                $item['completion'] ?? [],

            'restrictions' =>
                $item['restrictions'] ?? [],

            'dates' =>
                $item['dates'] ?? [],

            'roles' =>
                $item['roles'] ?? [],

            'url' =>
                (string)($item['url'] ?? ''),

            'externalurl' =>
                (string)($item['externalurl'] ?? ''),

            /*
             * For dynamically relevant entities, do not throw away
             * media/resources or child structures.
             */
            'media' =>
                array_values(
                    $item['media'] ?? []
                ),

            'children' =>
                array_values(
                    $item['children'] ?? []
                ),
        ];
    }

    /**
     * Authoritative current teacher workload.
     *
     * @return array<string, mixed>
     */
    private function teacher_work(): array {

        $attention =
            $this->agentcontext['activityattention']
            ?? [];

        $activities =
            $attention['activities']
            ?? [];

        $replytargets = [];
        $gradingtargets = [];

        foreach ($activities as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $teacher =
                is_array(
                    $activity['teacher'] ?? null
                )
                    ? $activity['teacher']
                    : [];

            $activityname =
                (string)($activity['name'] ?? '');

            $activityurl =
                (string)($activity['url'] ?? '');

            /*
             * Exact forum contribution branches that still have no
             * response from the current verified teacher.
             */
            $forum =
                is_array(
                    $teacher['forum'] ?? null
                )
                    ? $teacher['forum']
                    : [];

            foreach (
                $forum['items'] ?? []
                as $target
            ) {
                if (!is_array($target)) {
                    continue;
                }

                if (
                    !empty(
                        $target['hasmyresponse']
                    )
                ) {
                    continue;
                }

                $replytargets[] = [
                    'cmid' =>
                        (int)($activity['cmid'] ?? 0),

                    'activity' =>
                        $activityname,

                    'activityurl' =>
                        $activityurl,

                    'postid' =>
                        (int)($target['postid'] ?? 0),

                    'discussionid' =>
                        (int)($target['discussionid'] ?? 0),

                    'discussion' =>
                        (string)($target['discussionname'] ?? ''),

                    'subject' =>
                        (string)($target['subject'] ?? ''),

                    'userid' =>
                        (int)($target['userid'] ?? 0),

                    'author' =>
                        (string)($target['author'] ?? ''),

                    'message' =>
                        (string)($target['message'] ?? ''),

                    'hasanyreply' =>
                        !empty($target['hasanyreply']),

                    'responsebasis' =>
                        (string)($target['responsebasis'] ?? ''),

                    'url' =>
                        (string)($target['url'] ?? ''),
                ];
            }

            /*
             * Actual work without an actual Moodle grade.
             */
            $grading =
                is_array(
                    $teacher['grading'] ?? null
                )
                    ? $teacher['grading']
                    : [];

            foreach (
                $grading['ungraded_users'] ?? []
                as $user
            ) {
                if (!is_array($user)) {
                    continue;
                }

                $gradingtargets[] = [
                    'cmid' =>
                        (int)($activity['cmid'] ?? 0),

                    'activity' =>
                        $activityname,

                    'activityurl' =>
                        $activityurl,

                    'userid' =>
                        (int)($user['userid'] ?? 0),

                    'name' =>
                        (string)($user['name'] ?? ''),

                    'evidencebasis' =>
                        (string)($grading['evidencebasis'] ?? ''),
                ];
            }
        }

        return [
            'replycount' =>
                count($replytargets),

            'replytargets' =>
                $replytargets,

            'gradingcount' =>
                count($gradingtargets),

            'gradingtargets' =>
                $gradingtargets,
        ];
    }

    /**
     * Course-wide resource catalog.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resource_catalog(): array {

        $resources = [];

        foreach (
            $this->index['items'] ?? []
            as $item
        ) {
            if (!is_array($item)) {
                continue;
            }

            $activityname =
                (string)($item['name'] ?? '');

            $externalurl =
                trim(
                    (string)($item['externalurl'] ?? '')
                );

            if ($externalurl !== '') {
                $resources[] = [
                    'activity' =>
                        $activityname,
                    'section' =>
                        (string)($item['section'] ?? ''),
                    'label' =>
                        $activityname,
                    'provider' =>
                        'external',
                    'purpose' =>
                        '',
                    'url' =>
                        $externalurl,
                ];
            }

            foreach (
                $item['media'] ?? []
                as $resource
            ) {
                if (!is_array($resource)) {
                    continue;
                }

                $resources[] = [
                    'activity' =>
                        $activityname,

                    'section' =>
                        (string)($item['section'] ?? ''),

                    'label' =>
                        (string)($resource['label'] ?? ''),

                    'provider' =>
                        (string)($resource['provider'] ?? ''),

                    'purpose' =>
                        (string)($resource['purpose'] ?? ''),

                    'context' =>
                        (string)($resource['context'] ?? ''),

                    'url' =>
                        (string)($resource['url'] ?? ''),
                ];
            }
        }

        return $resources;
    }

    /**
     * Whole-course structural inventory.
     *
     * @return array<string, mixed>
     */
    private function inventory(): array {

        $types = [];

        foreach (
            $this->index['items'] ?? []
            as $item
        ) {
            if (!is_array($item)) {
                continue;
            }

            $type =
                (string)(
                    $item['modname']
                    ?? $item['type']
                    ?? 'other'
                );

            $types[$type] =
                ($types[$type] ?? 0) + 1;
        }

        ksort($types);

        return [
            'sections' =>
                count(
                    $this->index['sections'] ?? []
                ),

            'activitiesandresources' =>
                count(
                    $this->index['items'] ?? []
                ),

            'types' =>
                $types,
        ];
    }

    /**
     * Query terms from the current request and recent user task.
     *
     * @return array<int, string>
     */
    private function query_terms(
        string $question,
        array $history
    ): array {

        $text = $question;

        foreach (
            array_slice($history, -4)
            as $entry
        ) {
            if (
                is_array($entry) &&
                ($entry['role'] ?? '') === 'user'
            ) {
                $text .=
                    ' ' .
                    (string)($entry['content'] ?? '');
            }
        }

        $text =
            \core_text::strtolower($text);

        $parts =
            preg_split(
                '/[^\pL\pN]+/u',
                $text,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

        $stop = [
            'the', 'a', 'an', 'and', 'or',
            'to', 'of', 'in', 'on', 'for',
            'is', 'are', 'was', 'were',
            'i', 'me', 'my', 'you', 'your',
            'what', 'which', 'who', 'where',
            'how', 'do', 'does', 'did',
            'this', 'that', 'these', 'those',
            'other', 'another', 'else', 'more',
        ];

        $terms = [];

        foreach ($parts ?: [] as $part) {
            if (
                \core_text::strlen($part) < 3 ||
                in_array($part, $stop, true)
            ) {
                continue;
            }

            $terms[$part] = $part;
        }

        return array_values($terms);
    }

    /**
     * Extract Moodle/browser URLs from conversation text.
     *
     * @return array<int, string>
     */
    private function extract_urls(
        string $text
    ): array {

        preg_match_all(
            '~https?://[^\s<>"\']+~iu',
            $text,
            $matches
        );

        $urls = [];

        foreach ($matches[0] ?? [] as $url) {
            $url =
                rtrim(
                    $url,
                    ".,;!?)"
                );

            if ($url !== '') {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    /**
     * Recursively recover URLs from structured conversation context.
     *
     * @return array<int, string>
     */
    private function urls_from_array(
        array $data
    ): array {

        $urls = [];

        array_walk_recursive(
            $data,
            static function($value) use (&$urls): void {
                if (
                    is_string($value) &&
                    preg_match(
                        '~^https?://~i',
                        $value
                    )
                ) {
                    $urls[$value] = $value;
                }
            }
        );

        return array_values($urls);
    }

    /**
     * Parse Moodle entity identifiers from a URL.
     *
     * @return array<string, int>
     */
    private function url_identifiers(
        string $url
    ): array {

        $result = [
            'cmid' => 0,
            'discussionid' => 0,
            'postid' => 0,
        ];

        if (
            preg_match(
                '~(?:view\.php\?|[?&])id=(\d+)~iu',
                $url,
                $m
            )
        ) {
            $result['cmid'] = (int)$m[1];
        }

        if (
            preg_match(
                '~[?&]d=(\d+)~iu',
                $url,
                $m
            )
        ) {
            $result['discussionid'] =
                (int)$m[1];
        }

        if (
            preg_match(
                '~#p(\d+)\b~iu',
                $url,
                $m
            )
        ) {
            $result['postid'] =
                (int)$m[1];
        }

        return $result;
    }
}
