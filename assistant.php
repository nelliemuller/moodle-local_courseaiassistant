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

$courseid = required_param('courseid', PARAM_INT);
$conversationid = optional_param('conversationid', 0, PARAM_INT);
$returnurlraw = optional_param('returnurl', '', PARAM_LOCALURL);
$popup = optional_param('popup', 0, PARAM_BOOL);
$course = get_course($courseid);
require_course_login($course);
$coursecontext = context_course::instance($courseid);
require_capability('local/courseaiassistant:use', $coursecontext);

$config = \local_courseaiassistant\configuration::site_defaults();
$assistantname = (string)$config['assistantname'];

$pageparams = ['courseid' => $courseid];
if ($conversationid > 0) {
    $pageparams['conversationid'] = $conversationid;
}
if ($returnurlraw !== '') {
    $pageparams['returnurl'] = $returnurlraw;
}
if ($popup) {
    $pageparams['popup'] = 1;
}
$PAGE->set_url(new moodle_url('/local/courseaiassistant/assistant.php', $pageparams));
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);
$PAGE->set_pagelayout($popup ? 'embedded' : 'standard');
$PAGE->add_body_class($popup ? 'local-courseaiassistant-popup-page' : 'local-courseaiassistant-fullpage-page');
$PAGE->set_title($assistantname . ' — ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
if ($popup) {
    $PAGE->requires->js_call_amd('local_courseaiassistant/popup', 'init');
}

print $OUTPUT->header();
echo html_writer::start_div('course-ai-fullpage-view local_courseaiassistant');
$returnurl = $returnurlraw !== '' ? new moodle_url($returnurlraw) : new moodle_url('/course/view.php', ['id' => $courseid]);
if (!$popup) {
    echo html_writer::link($returnurl, get_string('returntocourse', 'local_courseaiassistant'), [
        'class' => 'btn btn-secondary mb-3 course-ai-return-button',
        'data-returnurl' => $returnurl->out(false),
    ]);
}
echo \local_courseaiassistant\ui::render_course(
    $course,
    $config,
    'fullpage',
    [
        'conversationid' => $conversationid,
        'returnurl' => $returnurl->out(false),
        'popup' => $popup,
    ]
);
echo html_writer::end_div();
print $OUTPUT->footer();
