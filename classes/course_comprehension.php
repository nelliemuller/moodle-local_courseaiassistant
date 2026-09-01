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
 * Course comprehension services.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_courseaiassistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a compact, permission-aware representation of a Moodle course for GenAI reasoning.
 *
 * The course map is evidence, not a pre-written answer. It preserves relationships between
 * sections, instructions, activities, completion conditions, badges, and the current user's
 * progress so the selected AI provider can reason across the course instead of returning
 * keyword matches.
 */
class course_comprehension {
    private \stdClass $course;
    private course_indexer $indexer;
    private array $index;
    private array $agentcontext;

    public function __construct(\stdClass $course, course_indexer $indexer, array $index, array $agentcontext) {
        $this->course = $course;
        $this->indexer = $indexer;
        $this->index = $index;
        $this->agentcontext = $agentcontext;
    }

    /**
     * @param string $question
     * @param array $history
     * @param array $pagecontext
     * @return array<string, mixed>
     */
    public function build(string $question, array $history, array $pagecontext = []): array {
        $exact = $this->exact_context($question, $pagecontext);

        // A specific Moodle activity/discussion is already a strong context boundary.
        // Do not pollute it with loosely related course matches. The full course map and
        // authoritative criteria remain available for relationships such as badges/completion.
        $results = empty($exact)
            ? $this->indexer->search($question, $this->index)
            : [];

        return [
            'course' => $this->index['course'],

            /*
             * Verified Moodle identity and capability perspective.
             */
            'usercontext' =>
                $this->agentcontext['user'] ?? [],

            /*
             * Live role-aware Moodle activity state.
             *
             * This contains completion conditions, actual grade state,
             * forum response state, availability and restrictions.
             */
            'activitystatus' =>
                $this->agentcontext['activityattention'] ?? [],

            /*
             * Actual Moodle grade configuration:
             * grade type, maximum, minimum, pass threshold,
             * scales and grade-dependent completion.
             */
            'gradingpolicy' =>
                $this->agentcontext['grading'] ?? [],

            /*
             * Teacher/manager work evidence.
             *
             * Empty for participant accounts.
             */
            'teacherwork' =>
                $this->agentcontext['teacherwork'] ?? [],

            /*
             * Permitted course-management evidence.
             */
            'coursemanagement' =>
                $this->agentcontext['course_management'] ?? [],

            /*
             * Permission-aware Moodle site and course administration
             * intelligence.
             *
             * This exposes only safe administrative structure that the
             * authenticated Moodle user is actually permitted to inspect.
             */
            'siteadministration' => (
                new site_administration_resolver(
                    $this->course
                )
            )->resolve(),

            /*
             * Authoritative learner/course completion structures.
             */
            'authoritativerequirements' => [
                'badges' =>
                    $this->agentcontext['badges'] ?? [],
                'coursecompletion' =>
                    $this->agentcontext['coursecompletioncriteria'] ?? [],
                'userprogress' =>
                    $this->agentcontext['progress'] ?? [],
            ],

            /*
             * Course structure and instructional content.
             */
            'coursemap' => $this->course_map(),
            'recurringpatterns' => $this->recurring_patterns(),

            /*
             * Dynamic Moodle-native context.
             *
             * The course map provides broad awareness. The resolver
             * expands the Moodle entities and live teacher work that
             * matter for this exact request and its conversation.
             */
            /*
             * Universal Moodle-native state for every accessible
             * activity and resource in this course.
             *
             * This supplements existing forum, grading, progress,
             * resource, and deep-context intelligence.
             */
            'activitystates' => (
                new activity_state_resolver(
                    $this->course
                )
            )->resolve_all(),

            /*
             * Permission-aware content and configuration stored
             * inside Moodle activities and resources.
             */
            'activitycontent' => (
                new activity_content_resolver(
                    $this->course
                )
            )->resolve_all(),

            /*
             * Module-specific deep content which does not live in
             * the main activity instance record, such as Book
             * chapters and Moodle-managed resource files.
             */
            'resourcedetails' => (
                new resource_detail_resolver(
                    $this->course
                )
            )->resolve_all(),

            /*
             * Dynamic registry of every installed Moodle activity
             * plugin, including third-party mod plugins.
             */
            'moduleregistry' => (
                $moduleregistry =
                    (new module_registry())->resolve_all()
            ),

            /*
             * Generic permission-aware fallback intelligence for
             * every activity instance, even when no specialist
             * adapter exists yet.
             */
            'universalmodules' => (
                new universal_module_resolver(
                    $this->course,
                    $moduleregistry
                )
            )->resolve_all(),

            /*
             * Deep permission-aware introspection of installed
             * Moodle activity modules.
             *
             * This complements universalmodules and specialistmodules
             * with verified module-level structure and capabilities.
             */
            'moduleintrospection' => (
                new module_introspection_resolver(
                    $this->course
                )
            )->resolve(),

            /*
             * Module-specific specialist intelligence.
             *
             * Unknown and third-party activities continue to use
             * universalmodules plus the generic adapter.
             */
            'specialistmodules' => (
                new module_adapter_manager(
                    $this->course
                )
            )->resolve_all(),

            /*
             * Actual authenticated-user Moodle state.
             *
             * Configuration says what Moodle requires.
             * This evidence says what Moodle currently records.
             */
            'currentuseractivitystate' => (
                new activity_user_state_resolver(
                    $this->course
                )
            )->resolve_current_user(),

            /*
             * Request-aware other-user activity evidence.
             *
             * It activates only when the question needs participant
             * evidence and the authenticated Moodle user has the
             * required authority.
             */
            'participantactivitystate' => (
                new participant_activity_state_resolver(
                    $this->course
                )
            )->resolve(
                $question
            ),

            /*
             * Pedagogical workflow evidence.
             *
             * This distinguishes raw Moodle grade state from whether
             * participant work is actually ready for teacher grading.
             */
            'forumworkstate' => (
                new forum_work_state_resolver(
                    $this->course
                )
            )->resolve(
                $question,
                $history
            ),

            /*
             * Universal pedagogical workflow.
             *
             * Forums retain the specialist Batch 9 evidence.
             * Assignment and Quiz add their own Moodle-native
             * workflow state without replacing grading evidence.
             */
            'activityworkstate' => (
                new activity_work_state_resolver(
                    $this->course
                )
            )->resolve(
                $question,
                $history
            ),

            /*
             * Actual Assignment submission content through Moodle's
             * submission plugin interfaces.
             */
            'assignmentsubmissioncontent' => (
                new assignment_submission_content_resolver(
                    $this->course
                )
            )->resolve(
                $question,
                $history
            ),

            /*
             * Quiz attempt/question evidence using Moodle's Quiz
             * and Question APIs, including manual-grading state.
             */
            'quizquestionevidence' => (
                new quiz_question_evidence_resolver(
                    $this->course
                )
            )->resolve(
                $question,
                $history
            ),

            'deepcontext' => (
                new moodle_context_resolver(
                    $this->index,
                    $this->agentcontext
                )
            )->resolve(
                $question,
                $history,
                $pagecontext
            ),

            'currentpage' => [
                'title' => $this->clean((string)($pagecontext['pagetitle'] ?? ''), 300),
                'url' => clean_param((string)($pagecontext['pageurl'] ?? ''), PARAM_URL),
            ],
            'exactcontext' => $exact,
            'relevantdetails' => $this->relevant_details($results, $exact),
            'conversation' => $this->conversation($history),
            'statistics' => $this->statistics(),
        ];
    }

    /**
     * Build a complete course map with instruction-bearing content but without dumping forum replies.
     */
    private function course_map(): array {
        $bysection = [];
        foreach ($this->index['sections'] as $section) {
            $number = (int)($section['number'] ?? 0);
            $bysection[$number] = [
                'number' => $number,
                'name' => (string)($section['name'] ?? ''),
                'instructions' => $this->clean((string)($section['summary'] ?? ''), 1800),
                'items' => [],
            ];
        }

        foreach ($this->index['items'] as $item) {
            $number = (int)($item['sectionnum'] ?? 0);
            if (!isset($bysection[$number])) {
                $bysection[$number] = [
                    'number' => $number,
                    'name' => (string)($item['section'] ?? ''),
                    'instructions' => '',
                    'items' => [],
                ];
            }
            $bysection[$number]['items'][] = $this->item_overview($item);
        }

        ksort($bysection);
        return array_values($bysection);
    }

    private function item_overview(array $item): array {
        return [
            'cmid' => (int)($item['cmid'] ?? 0),
            'name' => (string)($item['name'] ?? ''),
            'type' => (string)($item['type'] ?? ''),
            'modname' => (string)($item['modname'] ?? ''),
            'section' => (string)($item['section'] ?? ''),
            'instructions' => $this->clean((string)($item['description'] ?? ''), 1000),
            'completion' => $item['completion'] ?? [],

            /*
             * Preserve Moodle availability restrictions as part of
             * every activity/resource, not merely search metadata.
             */
            'restrictions' => $item['restrictions'] ?? [],

            'dates' => $item['dates'] ?? [],
            'url' => (string)($item['url'] ?? ''),
            'externalurl' => (string)($item['externalurl'] ?? ''),
            'roles' => $item['roles'] ?? [],
            'resources' => array_map(function(array $resource): array {
                return [
                    'label' => (string)($resource['label'] ?? ''),
                    'provider' => (string)($resource['provider'] ?? ''),
                    'purpose' => (string)($resource['purpose'] ?? ''),
                    'context' => $this->clean((string)($resource['context'] ?? ''), 280),
                    'url' => (string)($resource['url'] ?? ''),
                ];
            }, array_slice($item['media'] ?? [], 0, 8)),
            'discussiontitles' => array_values(array_filter(array_map(static function(array $child): string {
                return (string)($child['name'] ?? '');
            }, array_slice($item['children'] ?? [], 0, 12)))),
        ];
    }

    private function relevant_details(array $results, array $exact): array {
        $details = [];
        $seen = [];

        if (!empty($exact['activity']) && is_array($exact['activity'])) {
            $item = $exact['activity'];
            $seen[(int)($item['cmid'] ?? 0)] = true;
            $details[] = $this->full_item($item, false);
        }

        foreach (array_slice($results, 0, 8) as $item) {
            $cmid = (int)($item['cmid'] ?? 0);
            if ($cmid > 0 && isset($seen[$cmid])) {
                continue;
            }
            $seen[$cmid] = true;
            $details[] = $this->full_item($item, false);
        }
        return $details;
    }

    private function full_item(array $item, bool $includechildren): array {
        $data = $this->item_overview($item);
        $data['media'] = array_slice($item['media'] ?? [], 0, 12);
        if ($includechildren) {
            $data['children'] = array_slice($item['children'] ?? [], 0, 12);
        }
        return $data;
    }

    /**
     * Exact Moodle URLs outrank broad retrieval. A supplied/current forum discussion is expanded
     * to the parent forum instructions plus the visible posts in that one discussion.
     */
    private function exact_context(string $question, array $pagecontext): array {
        $sources = [$question, (string)($pagecontext['pageurl'] ?? '')];
        $discussionid = 0;
        $postid = 0;
        $cmid = 0;

        foreach ($sources as $source) {
            $source = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$discussionid && preg_match('~mod/forum/discuss\.php\?[^\s#]*\bd=(\d+)~iu', $source, $m)) {
                $discussionid = (int)$m[1];
            }
            if (!$postid && preg_match('~#p(\d+)\b~iu', $source, $m)) {
                $postid = (int)$m[1];
            }
            if (!$cmid && preg_match('~mod/[a-z0-9_]+/view\.php\?[^\s#]*\bid=(\d+)~iu', $source, $m)) {
                $cmid = (int)$m[1];
            }
        }

        if ($discussionid > 0) {
            $resolved = $this->indexer->get_discussion($discussionid, $this->index);
            if ($resolved) {
                return [
                    'kind' => 'forum_discussion',
                    'activity' => $this->full_item($resolved['activity'], false),
                    'discussion' => $this->trim_discussion($resolved['discussion'], $postid),
                ];
            }
        }

        if ($cmid > 0) {
            $item = $this->indexer->get_item($cmid, $this->index);
            if ($item) {
                return [
                    'kind' => 'activity',
                    'activity' => $this->full_item($item, true),
                ];
            }
        }
        return [];
    }

    private function trim_discussion(array $discussion, int $targetpostid = 0): array {
        $posts = [];
        $targetpost = null;
        foreach (array_slice($discussion['posts'] ?? [], 0, 60) as $post) {
            $entry = [
                'id' => (int)($post['id'] ?? 0),
                'author' => (string)($post['author'] ?? ''),
                'subject' => (string)($post['subject'] ?? ''),
                'text' => $this->clean((string)($post['text'] ?? ''), 4000),
                'isreply' => !empty($post['isreply']),
                'url' => (string)($post['url'] ?? ''),
                'target' => $targetpostid > 0 && (int)($post['id'] ?? 0) === $targetpostid,
            ];
            if ($entry['target']) {
                $targetpost = $entry;
            }
            $posts[] = $entry;
        }
        return [
            'id' => (int)($discussion['id'] ?? 0),
            'name' => (string)($discussion['name'] ?? ''),
            'url' => (string)($discussion['url'] ?? ''),
            'targetpostid' => $targetpostid,
            'targetpost' => $targetpost,
            'posts' => $posts,
        ];
    }

    /**
     * Detect repeated course design patterns such as weekly reflection or support activities.
     * This is structural evidence only; the AI still decides what the pattern means.
     */
    private function recurring_patterns(): array {
        $groups = [];
        foreach ($this->index['items'] as $item) {
            $key = $this->pattern_key((string)($item['name'] ?? ''));
            if ($key === '' || \core_text::strlen($key) < 5) {
                continue;
            }
            $groups[$key][] = [
                'name' => (string)$item['name'],
                'section' => (string)$item['section'],
                'type' => (string)$item['type'],
                'url' => (string)$item['url'],
            ];
        }

        $out = [];
        foreach ($groups as $key => $items) {
            if (count($items) < 2) {
                continue;
            }
            $sections = array_values(array_unique(array_filter(array_column($items, 'section'))));
            $out[] = [
                'pattern' => $key,
                'count' => count($items),
                'sections' => $sections,
                'items' => array_slice($items, 0, 30),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        return array_slice($out, 0, 25);
    }

    private function pattern_key(string $name): string {
        $key = \core_text::strtolower($name);
        $key = preg_replace('/\bweek\s*\d+\b/u', '', $key);
        $key = preg_replace('/\b(?:\d+)(?:st|nd|rd|th)?\s+session\b/u', 'session', $key);
        $key = preg_replace('/\([^)]*\bweek\s*\d+[^)]*\)/u', '', $key);
        $key = preg_replace('/\b\d+\b/u', '', $key);
        $key = preg_replace('/[^\pL\pN]+/u', ' ', $key);
        return trim(preg_replace('/\s+/u', ' ', $key));
    }

    private function conversation(array $history): array {
        $out = [];
        foreach (array_slice($history, -14) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = (string)($entry['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $out[] = [
                'role' => $role,
                'content' => $this->clean(
                    (string)($entry['content'] ?? ''),
                    2500
                ),

                /*
                 * Preserve Moodle entity/task context from previous
                 * turns so follow-ups such as "other", "next",
                 * "who else", "her reply", and "that one" retain
                 * concrete Moodle referents.
                 */
                'context' =>
                    is_array($entry['context'] ?? null)
                        ? $entry['context']
                        : [],
            ];
        }
        return $out;
    }

    private function statistics(): array {
        $types = [];
        $roles = [];
        foreach ($this->index['items'] as $item) {
            $type = (string)($item['modname'] ?? 'other');
            $types[$type] = ($types[$type] ?? 0) + 1;
            foreach ($item['roles'] ?? [] as $role) {
                $roles[$role] = ($roles[$role] ?? 0) + 1;
            }
        }
        ksort($types);
        ksort($roles);
        return [
            'sections' => count($this->index['sections']),
            'visibleitems' => count($this->index['items']),
            'itemtypes' => $types,
            'itemroles' => $roles,
        ];
    }

    private function clean(string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (\core_text::strlen($text) <= $limit) {
            return $text;
        }
        return \core_text::substr($text, 0, $limit) . '…';
    }
}
