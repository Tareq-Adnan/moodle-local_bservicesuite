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

use core\context_helper;


defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/completionlib.php');
/**
 * Class helper
 *
 * @package    local_bservicesuite
 * @copyright  2025 Brain Station 23 ltd <sales@brainstation-23.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tarekul Islam <tarekul.islam@brainstation-23.com>
 */
class helper {
    /**
     * Get all visible courses except the site course (id=1)
     *
     * @param int $courseid Optional course ID parameter (not used in current implementation)
     * @return array Array of filtered course objects containing id, fullname, shortname and visible fields
     */
    public static function total_courses($courseid = 0){
        $courses = get_courses('all', 'c.sortorder ASC', 'c.id, c.fullname, c.shortname, c.visible');
        $courses = array_filter($courses, function ($course) {
            return $course->visible && $course->id != 1;
        });

        return $courses;
    }

    public static function total_completions(&$courses)
    {
        foreach ($courses as $course) {
            $course->completion = self::completion($course);
        }
    }

    private static function completion($course)
    {
        $course     = get_course($course->id ?? $course);
        $completion = new \completion_info($course);
        $activities = $completion->get_activities();
        $completed  = 0;
        $total      = count($activities);
        $completed  = 0;

        foreach ($activities as $activity) {
            $data = $completion->get_data($activity, false);

            if (!empty($data->completionstate)) {
                $completed++;
            }
        }
        // Calculate percentage
        return $total > 0 ? round(($completed / $total) * 100, 2) : 0;
    }

    public static function get_completion($course) {
        $coursedata = [];
        $coursedata['id'] = $course->id;
        $coursedata['fullname'] = $course->fullname;
        $coursedata['shortname'] = $course->shortname;
        $coursedata['completion'] =  self::completion($course);
        return $coursedata;
    }
    
    public static function format_live_log_rows($logs) {

    $rows = [];

    foreach ($logs as $log) {

        $formattedTime = userdate($log->timecreated, "%e %B %Y, %I:%M:%S %p");
        $user = \core_user::get_user($log->userid);
        $userFullname = fullname($user);

        $contextlevel = $log->contextlevel;

        $course = $log->courseid;
        $coursename = $course ? get_course($course)->fullname : "";
        $component = $log->component ?? "";


        if ($log->eventname === "\\core\\event\\mycourses_viewed") {
            $eventName = "My courses viewed";
            $description = "$userFullname has viewed their my courses page";
        } else if ($log->eventname === "\\core\\event\\webservice_function_called") {

            $other = json_decode($log->other, true);
            $func = $other['function'] ?? "";

            $eventName = "Web service function called";
            $description = "The web service function '$func' has been called.";
        } else if ($log->action === 'viewed') {
            $eventName = $log->action;
            $description = "$userFullname $eventName $coursename";
        } else {
            $eventName = $log->action;
            $description = "Event occurd";
        }

        $origin = $log->origin;

        $ip = $log->ip;

        $rows[] = [
            "course"        => $course,
            "coursename"    => $coursename,
            "time"          => trim($formattedTime),
            "userfullname"  => $userFullname,
            "contextLevel"  => $contextlevel,
            "component"     => $component,
            "eventname"     => $eventName,
            "description"   => $description,
            "origin"        => $origin,
            "ip"            => $ip
        ];
    }

    return $rows;
}

}
