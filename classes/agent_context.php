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


namespace local_courseaiassistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a permission-aware snapshot of the current Moodle user, course and site.
 * The snapshot is deliberately filtered by Moodle capabilities before it is used by AI.
 */
class agent_context {
    private \stdClass $course;
    private \context_course $coursecontext;

    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext = \context_course::instance($course->id);
    }

    public function build(): array {
        global $CFG, $DB, $USER;

        $roles = get_user_roles($this->coursecontext, $USER->id, true);
        $rolenames = [];
        foreach ($roles as $role) {
            $name = role_get_name($role, $this->coursecontext, ROLENAME_ORIGINAL);
            if ($name !== '') {
                $rolenames[] = $name;
            }
        }
        if (is_siteadmin($USER)) {
            array_unshift($rolenames, 'Site administrator');
        }
        $rolenames = array_values(array_unique($rolenames));

        $capabilities = [
            'siteadmin' => is_siteadmin($USER),
            'canconfiguresite' => has_capability('moodle/site:config', \context_system::instance()),
            'caneditcourse' => has_capability('moodle/course:update', $this->coursecontext),
            'canmanageactivities' => has_capability('moodle/course:manageactivities', $this->coursecontext),
            'cangrade' => has_capability('moodle/grade:viewall', $this->coursecontext) || has_capability('moodle/grade:edit', $this->coursecontext),
            'canviewparticipants' => has_capability('moodle/course:viewparticipants', $this->coursecontext),
            'canviewreports' => has_capability('report/log:view', $this->coursecontext) || has_capability('report/completion:view', $this->coursecontext),
            'canviewhiddenactivities' => has_capability('moodle/course:viewhiddenactivities', $this->coursecontext),
            'canmanagecompletion' => has_capability('moodle/course:manageactivities', $this->coursecontext),
            'canmanagebadges' => has_capability('moodle/badges:manageglobalsettings', \context_system::instance()) || has_capability('moodle/badges:configurecriteria', $this->coursecontext),
            'canmanageenrolments' => has_capability('moodle/course:enrolconfig', $this->coursecontext),
        ];

        /*
         * Verified Moodle authority is authoritative.
         *
         * Assigned role names are descriptive only. Effective Moodle
         * capabilities determine the user's course perspective.
         *
         * A conversational claim such as "I am the admin" must never
         * increase access or change this verified authority.
         */
        $siteadmin = is_siteadmin($USER);

        $courseauthority = [
            'canupdatecourse' => has_capability(
                'moodle/course:update',
                $this->coursecontext,
                $USER
            ),
            'canmanageactivities' => has_capability(
                'moodle/course:manageactivities',
                $this->coursecontext,
                $USER
            ),
            'canviewallgrades' => has_capability(
                'moodle/grade:viewall',
                $this->coursecontext,
                $USER
            ),
            'caneditgrades' => has_capability(
                'moodle/grade:edit',
                $this->coursecontext,
                $USER
            ),
            'canviewparticipants' => has_capability(
                'moodle/course:viewparticipants',
                $this->coursecontext,
                $USER
            ),
            'canviewreports' => has_capability(
                'moodle/site:viewreports',
                $this->coursecontext,
                $USER
            ),
            'canmanagecompletion' => has_capability(
                'moodle/course:overridecompletion',
                $this->coursecontext,
                $USER
            ),
        ];

        /*
         * Determine course persona from effective Moodle authority.
         *
         * Role names are deliberately ignored. Moodle sites may rename
         * Student, Teacher, Participant, Manager, or any other role.
         *
         * Effective capabilities are the authority.
         */
        $persona = $this->resolve_course_persona(
            $courseauthority,
            $siteadmin
        );

        /*
         * Persona is authoritative.
         *
         * Participant accounts receive their own learner progress.
         * Teachers and managers receive teaching-work context instead.
         *
         * This prevents teacher accounts from being interpreted as
         * learners who personally need to complete the course.
         */
        $snapshot = [
            'user' => [
                /*
                 * Verified Moodle identity and authority.
                 *
                 * Assigned roles are descriptive.
                 * Effective capabilities control access.
                 */
                'userid' => (int)$USER->id,
                'roles' => $rolenames,
                'persona' => $persona,
                'siteadmin' => $siteadmin,
                'courseauthority' => $courseauthority,
                'capabilities' => $capabilities,
            ],
            'course' => [
                'id' => (int)$this->course->id,
                'fullname' => format_string($this->course->fullname),
                'shortname' => format_string($this->course->shortname),
                'format' => (string)$this->course->format,
                'startdate' => (int)$this->course->startdate,
                'enddate' => (int)$this->course->enddate,
                'enablecompletion' => !empty($this->course->enablecompletion),
            ],
        ];

        /*
         * Generalized role-aware activity evidence.
         *
         * This combines completion conditions, restrictions,
         * forum activity, grade state, and current role perspective.
         */
        $activityattention =
            new activity_attention($this->course);

        $snapshot['activityattention'] =
            $activityattention->build();

        /*
         * Authoritative Moodle grading policy.
         *
         * This tells the assistant HOW each activity is configured
         * to be graded, separately from whether individual work has
         * already received a grade.
         */
        $grading =
            new grading_evidence($this->course);

        $snapshot['grading'] =
            $grading->build();

        if ($persona === 'participant') {
            $snapshot['progress'] =
                $this->user_progress($USER->id);

            $snapshot['badges'] =
                $this->course_badges($USER->id);

            $snapshot['coursecompletioncriteria'] =
                $this->course_completion_criteria($USER->id);

        } else {
            /*
             * Teacher/manager personal context.
             *
             * This is read-only Moodle evidence about work which may
             * require the current educator's attention.
             */
            $teacherwork = new teacher_work($this->course);

            $snapshot['teacherwork'] =
                $teacherwork->build();

            /*
             * Keep course completion structure available as COURSE
             * information, not as the teacher's personal obligations.
             */
            $snapshot['coursecompletioncriteria'] =
                $this->course_completion_criteria(0);
        }
        // Teachers/managers can reason about reports and enrolment configuration without exposing private student data by default.
        if ($capabilities['canviewreports'] || $capabilities['canmanageenrolments']) {
            $snapshot['course_management'] = [
                'enrolmentmethods' => $this->enrolment_methods(),
                'reporturls' => $this->report_urls(),
            ];
        }

        return $snapshot;
    }

    /**
     * Resolve the authenticated user's course persona exclusively from
     * effective Moodle authority.
     *
     * This intentionally does not inspect Moodle role names or role
     * shortnames. Public Moodle sites may rename or customise roles.
     */
    private function resolve_course_persona(
        array $authority,
        bool $siteadmin
    ): string {
        /*
         * Course editing authority is the strongest course-level signal.
         */
        if (
            !empty($authority['canupdatecourse']) ||
            !empty($authority['canmanageactivities'])
        ) {
            return 'editing_teacher';
        }

        /*
         * Non-editing educator authority.
         */
        if (
            !empty($authority['canviewallgrades']) ||
            !empty($authority['caneditgrades']) ||
            !empty($authority['canviewreports']) ||
            !empty($authority['canmanagecompletion'])
        ) {
            return 'teacher';
        }

        /*
         * A site administrator remains trusted site authority even where
         * a particular course does not explicitly grant teaching powers.
         */
        if ($siteadmin) {
            return 'administrator';
        }

        /*
         * No verified educator authority means learner.
         *
         * "participant" is the plugin's internal learner persona and is
         * independent of the Moodle role's displayed name.
         */
        return 'participant';
    }

    private function user_progress(int $userid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/badgeslib.php');

        $completion = new \completion_info($this->course);
        $activities = [];
        $complete = 0;
        $tracked = 0;
        foreach ($completion->get_activities() as $cm) {
            if (!$cm->uservisible && !has_capability('moodle/course:viewhiddenactivities', $this->coursecontext)) {
                continue;
            }
            $data = $completion->get_data($cm, false, $userid);
            $state = (int)($data->completionstate ?? COMPLETION_INCOMPLETE);
            $iscomplete = in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
            $tracked++;
            if ($iscomplete) {
                $complete++;
            }
            $activities[] = [
                'cmid' => (int)$cm->id,
                'name' => format_string($cm->name),
                'type' => (string)$cm->modname,
                'complete' => $iscomplete,
                'state' => $state,
                'url' => !empty($cm->url) ? $cm->url->out(false) : '',
            ];
        }

        $coursecomplete = $DB->record_exists_select('course_completions',
            'course = :course AND userid = :userid AND timecompleted IS NOT NULL',
            ['course' => $this->course->id, 'userid' => $userid]);

        $badges = [];
        foreach (badges_get_user_badges($userid, $this->course->id, 0, 100, '') as $badge) {
            $badges[] = [
                'id' => (int)$badge->id,
                'name' => format_string($badge->name),
                'dateissued' => (int)($badge->dateissued ?? 0),
            ];
        }

        return [
            'trackedactivities' => $tracked,
            'completedactivities' => $complete,
            'remainingactivities' => max(0, $tracked - $complete),
            'percent' => $tracked ? round(($complete / $tracked) * 100) : null,
            'coursecomplete' => $coursecomplete,
            'activities' => $activities,
            'earnedbadges' => $badges,
        ];
    }

    /**
     * Read course badge criteria using Moodle's badge API when available.
     * Criteria details are human-readable so the AI can connect badges to the
     * actual activities and requirements configured by the course designer.
     */
    private function course_badges(int $userid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');

        $records = $DB->get_records('badge', ['courseid' => $this->course->id], 'name ASC', 'id,name,description,status,type');
        $out = [];
        foreach ($records as $record) {
            $entry = [
                'id' => (int)$record->id,
                'name' => format_string($record->name),
                'description' => trim(strip_tags(format_text((string)($record->description ?? ''), FORMAT_HTML, ['context' => $this->coursecontext]))),
                'status' => (int)$record->status,
                'earned' => false,
                'criteria' => [],
            ];

            try {
                if (class_exists('\\core_badges\\badge')) {
                    $badge = new \core_badges\badge((int)$record->id);
                    $earned = $badge->get_awards();
                    foreach ($earned as $award) {
                        if ((int)($award->userid ?? 0) === $userid) {
                            $entry['earned'] = true;
                            break;
                        }
                    }
                    foreach ($badge->get_criteria() as $criterion) {
                        $details = '';
                        $title = '';
                        if (is_object($criterion)) {
                            if (method_exists($criterion, 'get_title')) {
                                $title = trim(strip_tags((string)$criterion->get_title()));
                            }
                            if (method_exists($criterion, 'get_details')) {
                                $details = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$criterion->get_details())));
                            }
                        }
                        $entry['criteria'][] = [
                            'id' => (int)($criterion->id ?? 0),
                            'type' => (int)($criterion->criteriatype ?? 0),
                            'method' => (int)($criterion->method ?? 0),
                            'title' => $title,
                            'details' => $details,
                        ];
                    }
                }
            } catch (\Throwable $error) {
                debugging('AI Course Assistant badge criteria read failed: ' . $error->getMessage(), DEBUG_DEVELOPER);
            }

            // Database fallback also preserves criteria when a criteria class is unavailable.
            if (empty($entry['criteria'])) {
                $criteria = $DB->get_records('badge_criteria', ['badgeid' => $record->id], 'id ASC', 'id,criteriatype,method');
                foreach ($criteria as $criterion) {
                    $params = [];
                    if ($DB->get_manager()->table_exists(new \xmldb_table('badge_criteria_param'))) {
                        foreach ($DB->get_records('badge_criteria_param', ['critid' => $criterion->id], 'id ASC', 'id,name,value') as $param) {
                            $params[(string)$param->name] = (string)$param->value;
                        }
                    }
                    $entry['criteria'][] = [
                        'id' => (int)$criterion->id,
                        'type' => (int)$criterion->criteriatype,
                        'method' => (int)$criterion->method,
                        'params' => $params,
                    ];
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Return the configured course completion criteria and this user's status.
     */
    private function course_completion_criteria(int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $completion = new \completion_info($this->course);
        if (!$completion->is_enabled() || !$completion->has_criteria()) {
            return [];
        }

        $criteriaout = [];
        foreach ($completion->get_criteria() as $criterion) {
            $title = '';
            $details = '';
            if (method_exists($criterion, 'get_title')) {
                try {
                    $title = trim(strip_tags((string)$criterion->get_title()));
                } catch (\Throwable $error) {
                    $title = '';
                }
            }
            if (method_exists($criterion, 'get_details')) {
                try {
                    $details = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$criterion->get_details())));
                } catch (\Throwable $error) {
                    $details = '';
                }
            }

            $usercompletion = null;
            try {
                $usercompletion = $completion->get_user_completion($userid, $criterion);
            } catch (\Throwable $error) {
                $usercompletion = null;
            }

            $criteriaout[] = [
                'id' => (int)($criterion->id ?? 0),
                'type' => (int)($criterion->criteriatype ?? 0),
                'title' => $title,
                'details' => $details,
                'module' => (string)($criterion->module ?? ''),
                'moduleinstance' => (int)($criterion->moduleinstance ?? 0),
                'courseinstance' => (int)($criterion->courseinstance ?? 0),
                'gradepass' => isset($criterion->gradepass) ? (float)$criterion->gradepass : null,
                'timeend' => (int)($criterion->timeend ?? 0),
                'completed' => $usercompletion ? !empty($usercompletion->timecompleted) : false,
            ];
        }

        return [
            'aggregation' => $completion->get_aggregation_method(),
            'coursecomplete' => $completion->is_course_complete($userid),
            'criteria' => $criteriaout,
        ];
    }

    private function enrolment_methods(): array {
        global $DB;
        $rows = $DB->get_records('enrol', ['courseid' => $this->course->id], 'sortorder ASC', 'id,enrol,status,name');
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['type' => $row->enrol, 'enabled' => ((int)$row->status === ENROL_INSTANCE_ENABLED), 'name' => (string)$row->name];
        }
        return $out;
    }

    private function report_urls(): array {
        $id = (int)$this->course->id;
        return [
            'participants' => (new \moodle_url('/user/index.php', ['id' => $id]))->out(false),
            'grades' => (new \moodle_url('/grade/report/index.php', ['id' => $id]))->out(false),
            'completion' => (new \moodle_url('/report/completion/index.php', ['course' => $id]))->out(false),
            'logs' => (new \moodle_url('/report/log/index.php', ['id' => $id]))->out(false),
        ];
    }
}
