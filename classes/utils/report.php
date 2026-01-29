<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_bservicesuite\utils;

use completion_info;
use core\task\manager;
use core_user;
use grade_item;
use html_writer;
use local_bservicesuite\task\sent_assesment_report;
use stdClass;
use template;

/**
 * Class report
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /**
     * Generates an assessment report based on provided data
     *
     * @param array $data Array containing report data with keys:
     *                    - relateduserid: ID of the student
     *                    - contextinstanceid: ID of the activity
     *                    - courseid: ID of the course
     * @return void
     */
    public static function generate_assessment_report_task($data) {
        $studentid = $data['relateduserid'];
        $courseid = $data['courseid'];
        $activityid = $data['contextinstanceid'];

        $task = new sent_assesment_report();
        $task->set_custom_data(['studentid' => $studentid, 'courseid' => $courseid, 'activityid' => $activityid]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Sends an assessment report email to the user
     *
     * @param stdClass $data The report data to send
     * @return void
     */
    public static function sent_email($data, $user) {
        global $OUTPUT;

        $context = (array) $data;
        $msg = $OUTPUT->render_from_template('local_bservicesuite/report', $context);
        $msgtext = html_to_text($msg);

        $from = get_config('local_bservicesuite', 'fromemail');
        $subject = "Assessment Report";
        $user->mailformat = 1;

        if ($from) {
            email_to_user($user, $from, $subject, $msgtext, $msg);
        } else {
            send_system_email__to_user($msg, $user);
        }
    }

    /**
     * Gets the human-readable assessment type name from the module type
     *
     * @param string $type The module type (e.g. 'assign', 'quiz')
     * @return string The human-readable assessment type name
     */
    public static function get_assessment_type($type) {
        switch ($type) {
            case 'assign':
                return "Assignment";
            case 'quiz':
                return "Quiz";
        }
        return "";
    }

    /**
     * Gets the parent record for a given child/student
     *
     * @param int $child The ID of the student/child
     * @return object|false The parent record from local_bservicesuite_parents table, or false if not found
     */
    public static function get_parent($child) {
        global $DB;
        $relationdata = $DB->get_record('local_bservicesuite_parents', ['studentid' => $child]);
        $parent = core_user::get_user($relationdata->parentid);
        return $parent;
    }

    /**
     * Gets detailed report information for a specific course module and user
     *
     * @param int $cmid The course module ID
     * @param int $courseid The course ID
     * @param int $userid The user ID
     * @return stdClass Report details including completion date, grades, feedback etc.
     */
    public static function get_report_details($cmid, $courseid, $userid) {

        $report = new stdClass();

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($cmid);

        $type = $cm->modname;
        $completion = new completion_info($course);
        $cmcompletion = $completion->get_data($cm, false, $userid);
        $report->type = $type;
        $report->assessmentname = $cm->name;
        if (
            $cmcompletion->completionstate == COMPLETION_COMPLETE ||
            $cmcompletion->completionstate == COMPLETION_COMPLETE_PASS ||
            $cmcompletion->completionstate == COMPLETION_COMPLETE_FAIL
        ) {
            $report->completiondate = userdate($cmcompletion->timemodified);
        }

        $gradedata = grade_get_grades($courseid, 'mod', $type, $cm->instance, $userid);

        if ($gradedata->items[0]) {
            $gradeitem = $gradedata->items[0];

            if (isset($gradeitem->grades[$userid])) {
                $usergrade = $gradeitem->grades[$userid];
                $report->score = is_null($usergrade->grade) ? "" : format_float($usergrade->grade, 2);
                $report->total = format_float($gradeitem->grademax, 2);

                if (isset($usergrade->str_grade)) {
                    $report->grade = $usergrade->str_grade;
                } else if ($course->showgrades) {
                    $gradeitemobj = new grade_item(['id' => $gradeitem->id]);
                    $gradeletter = $gradeitemobj->get_grade($userid, false);

                    if ($gradeletter) {
                        $report->grade = $gradeletter->get_letter();
                    }
                }
                $report->passgrade = $gradeitem->gradepass;

                if (!is_null($usergrade->grade) && !is_null($report->passgrade)) {
                    $report->status = ($usergrade->grade >= $report->passgrade) ? get_string('pass', 'grades') : get_string('fail', 'grades');
                }
            }
        }

        if (!empty($usergrade->feedback)) {
            $report->feedback = format_text($usergrade->feedback, $usergrade->feedbackformat);
        }
        return $report;
    }
}
