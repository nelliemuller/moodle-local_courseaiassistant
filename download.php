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
 * Conversation download endpoint.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
header('X-Content-Type-Options: nosniff');

$courseid = required_param('courseid', PARAM_INT);
$id = required_param('id', PARAM_INT);
$format = optional_param('format', 'txt', PARAM_ALPHA);
require_sesskey();
$course = get_course($courseid);
require_course_login($course);
$coursecontext = context_course::instance($courseid);
require_capability('local/courseaiassistant:use', $coursecontext);

$conv = $DB->get_record('local_courseaiassistant_conv', ['id' => $id, 'userid' => $USER->id, 'courseid' => $courseid], '*', MUST_EXIST);
$messages = $DB->get_records('local_courseaiassistant_msg', ['conversationid' => $id], 'id ASC');
$filename = clean_filename($conv->title ?: 'course-conversation');

if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . s($conv->title) . '</title></head><body>';
    echo '<h1>' . s($conv->title) . '</h1><p>' . s(format_string($course->fullname)) . '</p>';
    foreach ($messages as $message) {
        echo '<h2>' . ($message->role === 'assistant' ? get_string('pluginname', 'local_courseaiassistant') : fullname($USER)) . '</h2>';
        echo '<p>' . nl2br(s($message->message)) . '</p>';
    }
    echo '</body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
echo $conv->title . "\n" . format_string($course->fullname) . "\n\n";
foreach ($messages as $message) {
    echo ($message->role === 'assistant' ? get_string('pluginname', 'local_courseaiassistant') : fullname($USER)) . ":\n" . $message->message . "\n\n";
}
