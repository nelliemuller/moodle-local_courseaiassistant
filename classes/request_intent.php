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
 * Central request intent classification.
 *
 * This does not determine Moodle permissions.
 * Moodle capabilities remain authoritative.
 *
 * Its purpose is to distinguish requests to CHANGE Moodle from
 * requests to read, understand, discuss, draft, compare or reason
 * about Moodle information.
 */
final class request_intent {

    /**
     * Does this request explicitly ask the assistant to mutate Moodle?
     */
    public function requests_moodle_write(string $question): bool {
        $q = $this->normalise($question);

        if ($q === '') {
            return false;
        }

        /*
         * Drafting/generation is NOT a Moodle write.
         *
         * "Reply to Olga" means draft a reply unless the user explicitly
         * asks the assistant to post/send/publish it in Moodle.
         */
        if (
            preg_match(
                '/\b(draft|write|compose|suggest|help me reply|give me a reply|reply to)\b/u',
                $q
            ) &&
            !preg_match(
                '/\b(post|publish|submit|send)\b.{0,40}\b(this|the|my|that)?\s*(reply|response|message)\b/u',
                $q
            )
        ) {
            return false;
        }

        /*
         * Reading, checking and reasoning about existing Moodle state
         * are never writes.
         */
        if (
            preg_match(
                '/\b(did|has|have|who|what|which|where|when|why|how|check|show|tell|list|explain|compare|summarize|summarise|relate)\b/u',
                $q
            ) &&
            !preg_match(
                '/\b(post|publish|submit|delete|remove|award|enrol|enroll|unenrol|unenroll|set|change|update)\b/u',
                $q
            )
        ) {
            return false;
        }

        /*
         * Negative instructions must not be mistaken for writes.
         *
         * Example:
         * "Do not answer her questions."
         */
        if (
            preg_match(
                '/\b(do not|don t|dont|never|without)\b.{0,45}\b(post|publish|submit|delete|remove|grade|award|enrol|enroll|set|change|update)\b/u',
                $q
            )
        ) {
            return false;
        }

        /*
         * Explicit Moodle mutations.
         */
        $patterns = [
            '/\b(post|publish|submit|send)\b.{0,50}\b(reply|response|message|forum post|submission)\b/u',

            '/\b(grade|mark)\b.{0,50}\b(participant|student|submission|assignment|forum|post|work)\b/u',

            '/\b(set|change|update)\b.{0,50}\b(grade|completion|restriction|setting|course|activity|section)\b/u',

            '/\b(delete|remove|hide|show)\b.{0,50}\b(post|discussion|activity|resource|section|user|participant)\b/u',

            '/\b(enrol|enroll|unenrol|unenroll)\b.{0,50}\b(user|participant|student|teacher)\b/u',

            '/\b(award|issue)\b.{0,50}\b(badge|certificate)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this request ask the assistant to author course work
     * on behalf of the authenticated learner?
     *
     * This method does not determine Moodle permissions.
     * The router applies it only to the verified learner persona.
     */
    public function requests_learner_authorship(string $question): bool {
        $q = $this->normalise($question);

        if ($q === '') {
            return false;
        }

        /*
         * Guidance and process questions are legitimate learning support.
         *
         * Examples:
         * "How do I introduce myself?"
         * "How should I write my introduction?"
         * "What should I include in my reflection?"
         * "How do I complete this activity?"
         */
        if (
            preg_match(
                '/^(how do i|how should i|how can i|what should i|'
                . 'what do i need to|what am i supposed to|'
                . 'where do i|when do i|why do i)\b/u',
                $q
            )
        ) {
            return false;
        }

        /*
         * Explanation, guidance, brainstorming and feedback are allowed.
         */
        if (
            preg_match(
                '/\b(explain|clarify|help me understand|guide me|'
                . 'brainstorm|give me ideas|ask me questions|'
                . 'what should i consider|feedback|review|'
                . 'comment on|what can i improve)\b/u',
                $q
            )
        ) {
            return false;
        }

        /*
         * Explicit requests to produce submission-ready learner work.
         *
         * Examples:
         * "Write my post."
         * "Draft this reflection."
         * "Answer this assignment."
         */
        if (
            preg_match(
                '/\b(write|draft|compose|complete|generate|finish|'
                . 'answer|reply|respond|rewrite|polish)\b'
                . '.{0,60}\b(my|the|this|that|a|an)\b'
                . '.{0,40}\b(post|reply|response|reflection|answer|'
                . 'assignment|submission|script|discussion|forum|'
                . 'essay|report|presentation)\b/u',
                $q
            )
        ) {
            return true;
        }

        /*
         * Direct requests explicitly asking the assistant to do the work
         * for the learner.
         */
        if (
            preg_match(
                '/\b(write|draft|compose|complete|generate|finish|'
                . 'answer|reply|respond|rewrite|polish)\b'
                . '.{0,80}\b(for me|on my behalf)\b/u',
                $q
            )
        ) {
            return true;
        }

        /*
         * Submission-ready work requested without a normal authorship verb.
         */
        if (
            preg_match(
                '/\b(give me|provide me|make me|prepare me)\b'
                . '.{0,50}\b(post|reply|response|reflection|answer|'
                . 'assignment|submission|script|discussion|forum|'
                . 'essay|report|presentation)\b/u',
                $q
            )
        ) {
            return true;
        }

        return false;
    }

    private function normalise(string $text): string {
        $text = \core_text::strtolower(
            html_entity_decode(
                strip_tags($text),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        $text = preg_replace(
            '/[^\p{L}\p{N}\s]+/u',
            ' ',
            $text
        );

        return trim(
            preg_replace('/\s+/u', ' ', $text)
        );
    }
}
