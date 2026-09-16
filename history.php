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

function local_courseaiassistant_history_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !confirm_sesskey((string)($data['sesskey'] ?? ''))) {
    local_courseaiassistant_history_response(['error' => get_string('invalidrequest', 'local_courseaiassistant')], 403);
}

$courseid = (int)($data['courseid'] ?? 0);
$action = (string)($data['action'] ?? 'list');
$course = get_course($courseid);
require_course_login($course);
$coursecontext = context_course::instance($courseid);
require_capability('local/courseaiassistant:use', $coursecontext);

$userid = $USER->id;

if ($action === 'list') {
    $rows = $DB->get_records('local_courseaiassistant_conv', ['userid' => $userid, 'courseid' => $courseid], 'timemodified DESC', 'id,title,timecreated,timemodified', 0, 50);
    local_courseaiassistant_history_response(['conversations' => array_values($rows)]);
}

if ($action === 'get') {
    $id = (int)($data['id'] ?? 0);
    $conv = $DB->get_record('local_courseaiassistant_conv', ['id' => $id, 'userid' => $userid, 'courseid' => $courseid], '*', MUST_EXIST);
    $messages = $DB->get_records(
        'local_courseaiassistant_msg',
        ['conversationid' => $conv->id],
        'id ASC',
        'id,role,message,contextjson,timecreated'
    );
    local_courseaiassistant_history_response(['conversation' => $conv, 'messages' => array_values($messages)]);
}

if ($action === 'save') {
    $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
    if (!$messages) {
        local_courseaiassistant_history_response(['error' => get_string('nomessagestosave', 'local_courseaiassistant')], 400);
    }
    $id = (int)($data['id'] ?? 0);
    $title = trim((string)($data['title'] ?? get_string('conversationdefaulttitle', 'local_courseaiassistant')));
    $title = shorten_text(clean_param($title, PARAM_TEXT), 100);
    $now = time();

    if ($id) {
        $conv = $DB->get_record('local_courseaiassistant_conv', ['id' => $id, 'userid' => $userid, 'courseid' => $courseid], '*', MUST_EXIST);
        $conv->title = $title ?: $conv->title;
        $conv->timemodified = $now;
        $DB->update_record('local_courseaiassistant_conv', $conv);
        $DB->delete_records('local_courseaiassistant_msg', ['conversationid' => $id]);
    } else {
        $conv = (object)['userid' => $userid, 'courseid' => $courseid, 'title' => $title ?: get_string('conversationdefaulttitle', 'local_courseaiassistant'), 'timecreated' => $now, 'timemodified' => $now];
        $id = $DB->insert_record('local_courseaiassistant_conv', $conv);
    }

    foreach (array_slice($messages, -100) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = ($message['type'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($message['text'] ?? ''));
        if (core_text::strlen($text) > 20000) {
            $text = core_text::substr($text, 0, 20000);
        }
        $context = is_array($message['context'] ?? null) ? $message['context'] : [];
        $contextjson = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if ($contextjson !== null && core_text::strlen($contextjson) > 20000) {
            $contextjson = null;
        }

        if ($text === '') {
            continue;
        }

        $DB->insert_record('local_courseaiassistant_msg', (object)[
            'conversationid' => $id,
            'role' => $role,
            'message' => $text,
            'contextjson' => $contextjson,
            'timecreated' => $now,
        ]);
    }
    local_courseaiassistant_history_response(['saved' => true, 'id' => $id]);
}

if ($action === 'rename') {
    $id = (int)($data['id'] ?? 0);
    $conv = $DB->get_record('local_courseaiassistant_conv', ['id' => $id, 'userid' => $userid, 'courseid' => $courseid], '*', MUST_EXIST);
    $conv->title = shorten_text(clean_param((string)($data['title'] ?? ''), PARAM_TEXT), 100);
    $conv->timemodified = time();
    $DB->update_record('local_courseaiassistant_conv', $conv);
    local_courseaiassistant_history_response(['renamed' => true]);
}

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    $conv = $DB->get_record('local_courseaiassistant_conv', ['id' => $id, 'userid' => $userid, 'courseid' => $courseid], '*', MUST_EXIST);
    $DB->delete_records('local_courseaiassistant_msg', ['conversationid' => $conv->id]);
    $DB->delete_records('local_courseaiassistant_conv', ['id' => $conv->id]);
    local_courseaiassistant_history_response(['deleted' => true]);
}

if ($action === 'clear') {
    $ids = $DB->get_fieldset_select('local_courseaiassistant_conv', 'id', 'userid = ? AND courseid = ?', [$userid, $courseid]);
    if ($ids) {
        list($sql, $params) = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('local_courseaiassistant_msg', "conversationid $sql", $params);
    }
    $DB->delete_records('local_courseaiassistant_conv', ['userid' => $userid, 'courseid' => $courseid]);
    local_courseaiassistant_history_response(['cleared' => true]);
}

local_courseaiassistant_history_response(['error' => get_string('unknownaction', 'local_courseaiassistant')], 400);
