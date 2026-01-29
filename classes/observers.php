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

namespace local_bservicesuite;

use local_bservicesuite\utils\report;
use stdClass;

/**
 * Class observers
 *
 * @package    local_bservicesuite
 * @copyright  2025 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observers {
    /**
     * Observer for user creation event.
     *
     * @param \core\event\user_created $event The user created event
     * @return void
     */
    public static function create_user(\core\event\user_created $event) {
        $eventdata = $event->get_data();
        $userid = $eventdata['objectid'];
        $data   = helper::create_or_update_user($userid);
        $result = helper::sync($data->payload);
        $result ? helper::mark_synced($userid, $result) : null;
    }

    /**
     * Observer for user update event.
     *
     * @param \core\event\user_updated $event The user updated event
     * @return void
     */
    public static function update_user(\core\event\user_updated $event) {
        $edata  = $event->get_data();
        $userid = $edata['objectid'];
        $data   = helper::create_or_update_user($userid);
        $result = helper::sync($data->payload);
        $result ? helper::mark_synced($userid, $result) : null;
    }

    /**
     * Observer for user deletion event.
     *
     * @param \core\event\user_deleted $event The user deleted event
     * @return void
     */
    public static function delete_user(\core\event\user_deleted $event) {
        $edata  = $event->get_data();
        helper::delete_user($edata);
    }

    /**
     * Observer for user deletion event.
     *
     * @param \core\event\course_created $event The user deleted event
     * @return void
     */
    public static function create_course(\core\event\course_created $event) {
        $edata  = $event->get_data();
        $courseid = $edata['courseid'];
        helper::create_or_update_platform_course($courseid);
    }

    /**
     * Observer for user deletion event.
     *
     * @param \core\event\course_updated $event The user deleted event
     * @return void
     */
    public static function update_course(\core\event\course_updated $event) {
        $edata  = $event->get_data();
        $courseid = $edata['courseid'];
        helper::create_or_update_platform_course($courseid, true);
    }

    /**
     * Observer for submission graded event.
     *
     * @param \mod_assign\event\submission_graded $event The submission graded event
     * @return void
     */
    public static function submission_report(\mod_assign\event\submission_graded $event) {
        $edata = $event->get_data();
        report::generate_assessment_report_task($edata);
    }

    /**
     * Observer for quiz submitted graded event.
     *
     * @param \mod_quiz\event\attempt_submitted $event The submission graded event
     * @return void
     */
    public static function quiz_report(\mod_quiz\event\attempt_submitted $event) {
        $edata = $event->get_data();
        report::generate_assessment_report_task($edata);
    }

    /**
     * Observer for quiz manual graded event.
     *
     * @param \mod_quiz\event\attempt_manual_grading_completed $event The submission graded event
     * @return void
     */
    public static function quiz_manual_report(\mod_quiz\event\attempt_manual_grading_completed $event) {
        $edata = $event->get_data();
        report::generate_assessment_report_task($edata);
    }

    /**
     * Observer for manual graded event.
     *
     * @param \core\event\user_graded $event The submission graded event
     * @return void
     */
    public static function gradebook_report(\core\event\user_graded $event) {
        $edata = $event->get_data();

        $gradeitemid = $edata['other']['itemid'];
        $gradeitem = \grade_item::fetch(['id' => $gradeitemid]);
        $cm = get_coursemodule_from_instance($gradeitem->itemmodule, $gradeitem->iteminstance, $gradeitem->courseid, false);
        $data = [
            'relateduserid' => $edata['relateduserid'],
            'courseid' => $edata['contextinstanceid'],
            'contextinstanceid' => $cm->id,
        ];

        report::generate_assessment_report_task($data);
    }
}
