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

namespace local_courseaiassistant\ai;

defined('MOODLE_INTERNAL') || die();

/** Google Gemini provider using Moodle's server-side curl client. */
class gemini_provider implements provider_interface {
    private string $apikey;
    private string $model;

    public function __construct(array $config = []) {
        $this->apikey = trim((string)($config['geminiapikey'] ?? get_config('local_courseaiassistant', 'geminiapikey')));
        $this->model = trim((string)($config['geminimodel'] ?? $config['model'] ?? get_config('local_courseaiassistant', 'model')));
        if ($this->model === '') {
            $this->model = 'gemini-3.7-flash';
        }
    }

    public function ask(string $instructions, string $input): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($this->apikey === '') {
            throw new \moodle_exception('missinggeminikey', 'local_courseaiassistant');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent';
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $instructions]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $input]],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => 2200,
                'thinkingConfig' => [
                    'thinkingLevel' => 'medium',
                ],
            ],
        ];

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'x-goog-api-key: ' . $this->apikey,
        ]);
        $response = $curl->post($url, json_encode($payload), [
            'CURLOPT_TIMEOUT' => 90,
            'CURLOPT_CONNECTTIMEOUT' => 20,
        ]);
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidairesponse', 'local_courseaiassistant');
        }
        if ($httpcode >= 400 || !empty($decoded['error'])) {
            throw new \Exception((string)($decoded['error']['message'] ?? 'Gemini API error.'));
        }
        $text = (string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if (trim($text) === '') {
            throw new \moodle_exception('emptyairesponse', 'local_courseaiassistant');
        }
        return trim($text);
    }
}
