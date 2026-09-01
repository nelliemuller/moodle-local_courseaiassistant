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

/** OpenAI provider using the Responses API and Moodle's server-side curl client. */
class openai_provider implements provider_interface {
    private string $apikey;
    private string $model;
    private string $reasoning;

    public function __construct(array $config = []) {
        $this->apikey = trim((string)($config['openaiapikey'] ?? get_config('local_courseaiassistant', 'openaiapikey')));
        $this->model = trim((string)($config['openaimodel'] ?? get_config('local_courseaiassistant', 'openaimodel')));
        $this->reasoning = trim((string)($config['openaireasoning'] ?? get_config('local_courseaiassistant', 'openaireasoning')));
        if ($this->model === '') {
            $this->model = 'gpt-5.6-terra';
        }
        if (!in_array($this->reasoning, ['none', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
            $this->reasoning = 'medium';
        }
    }

    public function ask(string $instructions, string $input): string {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');

        if ($this->apikey === '') {
            throw new \moodle_exception('missingopenaikey', 'local_courseaiassistant');
        }

        $salt = (string)($CFG->passwordsaltmain ?? $CFG->wwwroot ?? 'moodle');
        $safetyid = hash_hmac('sha256', (string)$USER->id, $salt);
        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
            'reasoning' => ['effort' => $this->reasoning],
            'max_output_tokens' => 2400,
            'safety_identifier' => $safetyid,
        ];

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);
        $response = $curl->post('https://api.openai.com/v1/responses', json_encode($payload), [
            'CURLOPT_TIMEOUT' => 120,
            'CURLOPT_CONNECTTIMEOUT' => 20,
        ]);
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidairesponse', 'local_courseaiassistant');
        }
        if ($httpcode >= 400 || !empty($decoded['error'])) {
            throw new \Exception((string)($decoded['error']['message'] ?? 'OpenAI API error.'));
        }

        // SDKs expose output_text as a convenience. REST responses also expose output items,
        // so support both representations for forward compatibility.
        $text = trim((string)($decoded['output_text'] ?? ''));
        if ($text === '') {
            $parts = [];
            foreach ($decoded['output'] ?? [] as $item) {
                if (($item['type'] ?? '') !== 'message') {
                    continue;
                }
                foreach ($item['content'] ?? [] as $content) {
                    if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                        $parts[] = (string)$content['text'];
                    }
                }
            }
            $text = trim(implode("\n", $parts));
        }
        if ($text === '') {
            throw new \moodle_exception('emptyairesponse', 'local_courseaiassistant');
        }
        return $text;
    }
}
