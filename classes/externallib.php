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

use context_course;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;


/**
 * Class externallib
 *
 * @package    local_bservicesuite
 * @copyright  2025 Brain Station 23 ltd <sales@brainstation-23.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tarekul Islam <tarekul.islam@brainstation-23.com>
 */
class externallib extends external_api {
    /**
     * Returns description of get_analytics parameters
     *
     * @return external_function_parameters Parameters structure
     */
    public static function get_analytics_parameters() {
        return new external_function_parameters(
            [
                'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_OPTIONAL, 0),
            ]
        );
    }

    /**
     * Get analytics data
     *
     * @return [] | void Analytics function parameters
     */
    public static function get_analytics($courseid = 0, $logs = 0) {
        global $DB;

        $params = self::validate_parameters(
            self::get_analytics_parameters(),
            [
                'courseid' => $courseid,
        ]);

        has_all_capabilities(['local/bsservicessuite:view'], context_system::instance());

        $response = [
            'data' => [],
            'logs' => [],
            'totalcourse' => 0,
            'totaluser' => 0
        ];

        $logs    = $DB->get_records('logstore_standard_log', null, 'timecreated DESC', '*', 0, 10);
        $logs    = helper::format_live_log_rows($logs);
        $logs    = array_values($logs);

        if ($params['courseid'] != 0) {
            $course  = helper::get_completion(get_course($params['courseid']));
            $context = context_course::instance($params['courseid']);
            $users   = get_enrolled_users($context);

            $response['data']        = [$course];
            $response['logs']        = $logs;
            $response['totalcourse'] = 1;
            $response['totaluser']   = count($users);
            return $response;
        }

        $courses = helper::total_courses();
        $users   = get_users(false);
        helper::total_completions($courses);

        return [
            'data' => array_values($courses),
            'logs' => $logs,
            'totalcourse' => count($courses),
            'totaluser' => $users
        ];
    }

    /**
     * Returns description of get_analytics return values
     *
     * @return external_single_structure structure
     */
    public static function get_analytics_returns() {
        return new external_single_structure(
            [
                'data' => new external_multiple_structure(
                new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Course ID'),
                        'fullname' => new external_value(PARAM_TEXT, 'Course Fullname'),
                        'shortname' => new external_value(PARAM_TEXT, 'Course Shortname'),
                        'completion' => new external_value(PARAM_FLOAT, 'Total number of completion'),
                    ],
                )),
                'logs' => new external_multiple_structure(
                new external_single_structure([
                        'course'     => new external_value(PARAM_INT, 'Course ID'),
                        'coursename' => new external_value(PARAM_TEXT, 'Course Fullname'),
                        'time'       => new external_value(PARAM_TEXT, 'Course Shortname'),
                        'userfullname' => new external_value(PARAM_TEXT, 'User name'),
                        'contextlevel' => new external_value(PARAM_INT, 'Context Level', VALUE_OPTIONAL),
                        'component' => new external_value(PARAM_TEXT, 'component name'),
                        'eventname' => new external_value(PARAM_TEXT, 'Event name'),
                        'description' => new external_value(PARAM_TEXT, 'log description'),
                        'origin' => new external_value(PARAM_TEXT, 'log origin'),
                        'ip' => new external_value(PARAM_TEXT, 'request ip'),
                    ],
                ), 'logs', VALUE_OPTIONAL),
                'totalcourse' => new external_value(PARAM_INT, 'Total number of courses'),
                'totaluser' => new external_value(PARAM_INT, 'Total number of courses'),

            ],
        );
    }
}
