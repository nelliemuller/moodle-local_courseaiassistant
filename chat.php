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


require_once(__DIR__ . '/../../config.php');

require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function local_courseaiassistant_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    local_courseaiassistant_reply(['response' => get_string('invalidrequest', 'local_courseaiassistant')], 400);
}

$courseid = (int) ($data['courseid'] ?? 0);
$message = trim((string) ($data['message'] ?? ''));
$requestsesskey = (string) ($data['sesskey'] ?? '');
$rawhistory = is_array($data['history'] ?? null) ? $data['history'] : [];
$history = [];
foreach (array_slice($rawhistory, -20) as $historyitem) {
    if (!is_array($historyitem)) {
        continue;
    }

    $historyrole = (string)($historyitem['role'] ?? $historyitem['type'] ?? '');
    $type = $historyrole === 'assistant' ? 'assistant' : 'user';
    $text = trim((string)($historyitem['content'] ?? $historyitem['text'] ?? ''));
    if ($text === '') {
        continue;
    }
    if (core_text::strlen($text) > 4000) {
        $text = core_text::substr($text, 0, 4000);
    }
    $cleanhistoryitem = [
        'type' => $type,
        'text' => $text,
    ];

    $historycontext = is_array($historyitem['context'] ?? null)
        ? $historyitem['context']
        : [];
    $cmid = (int)($historycontext['cmid'] ?? 0);

    if ($cmid > 0) {
        $cleanhistoryitem['context'] = [
            'cmid' => $cmid,
        ];
    }

    $history[] = $cleanhistoryitem;
}
$pagecontext = [
    'pageurl' => clean_param((string)($data['pageurl'] ?? ''), PARAM_URL),
    'pagetitle' => clean_param((string)($data['pagetitle'] ?? ''), PARAM_TEXT),
];

if (!confirm_sesskey($requestsesskey)) {
    local_courseaiassistant_reply(['response' => get_string('invalidsessionkey', 'local_courseaiassistant')], 403);
}

if (!$courseid || $message === '') {
    local_courseaiassistant_reply(['response' => get_string('questionrequired', 'local_courseaiassistant')], 400);
}

if (core_text::strlen($message) > 4000) {
    local_courseaiassistant_reply(['response' => get_string('questiontoolong', 'local_courseaiassistant')], 413);
}

$course = get_course($courseid);
require_course_login($course);
$coursecontext = context_course::instance($courseid);
require_capability('local/courseaiassistant:use', $coursecontext);

$config = \local_courseaiassistant\configuration::site_defaults();
$config['responselanguage'] = \local_courseaiassistant\configuration::response_language($config);

/*
 * Critical fail-safe boundary.
 *
 * Course intelligence must NEVER corrupt the JSON response or
 * interfere with Moodle if one evidence service fails.
 */
try {
    $router = new \local_courseaiassistant\course_router(
        $course,
        $config
    );

    $answer = $router->answer(
        $message,
        $history,
        $pagecontext
    );

    local_courseaiassistant_reply($answer);

} catch (\Throwable $error) {
    debugging(
        'COURSEAIASSISTANT CHAT ERROR: ' .
        get_class($error) . ': ' .
        $error->getMessage() .
        ' in ' .
        $error->getFile() .
        ':' .
        $error->getLine(),
        DEBUG_DEVELOPER
    );

    local_courseaiassistant_reply(
        [
            'response' =>
                get_string('coursecheckfailed', 'local_courseaiassistant'),
            'scope' => 'course',
            'context' => [
                'status' => 'data_error',
            ],
        ],
        500
    );
}
