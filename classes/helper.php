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
use core_user;
use stdClass;
use curl;


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
     * Endpoint URL path for user synchronization with remote system
     * Used to construct the full sync URL by appending to the platform base URL
     */
    private const USER_SYNC_ENDPOINT = '/api/school/sync-user';

    /**
     * Get all visible courses except the site course (id=1)
     *
     * @param int $courseid Optional course ID parameter (not used in current implementation)
     * @return array Array of filtered course objects containing id, fullname, shortname and visible fields
     */
    public static function total_courses($courseid = 0) {
        $courses = get_courses('all', 'c.sortorder ASC', 'c.id, c.fullname, c.shortname, c.visible');
        $courses = array_filter($courses, function ($course) {
            return $course->visible && $course->id != 1;
        });

        return $courses;
    }

    /**
     * Calculate completion percentage for each course in an array of courses
     *
     * @param array $courses Reference to array of course objects to calculate completion for
     * @return void Updates course objects in place with completion percentage
     */
    public static function total_completions(&$courses) {
        foreach ($courses as $course) {
            $course->completion = self::completion($course);
            $context = context_course::instance($course->id);
            $users   = get_enrolled_users($context, 'mod/quiz:attempt');
            $course->enrolled = count($users);
        }
    }

    /**
     * Calculate completion percentage for a single course
     *
     * @param object|int $course Course object or course ID to calculate completion for
     * @return float Percentage of course activities completed, rounded to 2 decimal places
     */
    private static function completion($course) {
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

    /**
     * Get completion data for a single course
     *
     * @param object $course Course object to get completion data for
     * @return array Array containing course id, fullname, shortname and completion percentage
     */
    public static function get_completion($course) {
        $coursedata = [];
        $coursedata['id'] = $course->id;
        $coursedata['fullname'] = $course->fullname;
        $coursedata['shortname'] = $course->shortname;
        $coursedata['completion'] = self::completion($course);
        return $coursedata;
    }

    /**
     * Format log entries into standardized rows for display
     *
     * @param array $logs Array of log objects containing event data
     * @return array Array of formatted log rows with course, user, time and event details
     */
    public static function format_live_log_rows($logs) {

        $rows = [];

        foreach ($logs as $log) {
            $formattedtime = userdate($log->timecreated, "%e %B %Y, %I:%M:%S %p");
            $user = core_user::get_user($log->userid);
            $userfullname = fullname($user);

            $contextlevel = $log->contextlevel;
            $course = $log->courseid;
            $coursename = $course ? get_course($course)->fullname : "";
            $component = $log->component ?? "";

            if ($log->eventname === "\\core\\event\\mycourses_viewed") {
                $eventname = "My courses viewed";
                $description = "$userfullname has viewed their my courses page";
            } else if ($log->eventname === "\\core\\event\\webservice_function_called") {
                $other = json_decode($log->other, true);
                $func = $other['function'] ?? "";

                $eventname = "Web service function called";
                $description = "The web service function '$func' has been called.";
            } else if ($log->action === 'viewed') {
                $eventname = $log->action;
                $description = "$userfullname $eventname $coursename";
            } else {
                $eventname = $log->action;
                $description = "Event occurd";
            }

            $origin = $log->origin;
            $ip = $log->ip;

            $rows[] = [
            "course"        => $course,
            "coursename"    => $coursename,
            "time"          => trim($formattedtime),
            "userfullname"  => $userfullname,
            "contextLevel"  => $contextlevel,
            "component"     => $component,
            "eventname"     => $eventname,
            "description"   => $description,
            "origin"        => $origin,
            "ip"            => $ip,
            ];
        }

        return $rows;
    }

    /**
     * Creates or updates a user sync record in the local_bservice_user_sync table
     *
     * @param int $userid The ID of the user to create/update sync record for
     * @return object The created/updated record object containing sync data
     */
    public static function create_or_update_user($userid) {
        global $DB;

        $user   = core_user::get_user($userid);
        $exists = $DB->get_record('local_bservice_user_sync', ['userid' => $user->id]);

        $record = self::build_record($user);
        if ($exists) {
            $record->id  = $exists->id;
            $DB->update_record('local_bservice_user_sync', $record);
            return $record;
        }

        $DB->insert_record('local_bservice_user_sync', $record);
        return $record;
    }

    /**
     * Deletes a user's sync record and notifies remote system
     *
     * Removes the user's record from the local_bservice_user_sync table and sends a
     * sync request to the remote system indicating the user was deleted.
     *
     * @param int $userid The ID of the user to delete
     * @return void
     */
    public static function delete_user($data) {
        global $DB;

        $userdata = $data['other'];

        $DB->delete_records('local_bservice_user_sync', ['userid' => $data['objectid']]);
        $data           = new stdClass();
        $data->username = $userdata['username'];
        $data->deleted  = 1;
        self::sync(json_encode($data));
    }

    /**
     * Synchronizes user data with a remote system via HTTP POST request
     *
     * @param string $data JSON encoded user data to sync
     * @return array|bool Returns decoded JSON response on success, false on failure
     */
    public static function sync($data) {
        global $CFG;
        $endpoint  = get_config('local_bservicesuite', 'platformurl');
        $remoteurl = $endpoint . self::USER_SYNC_ENDPOINT;
        $moodleurl = $CFG->wwwroot;

        if (empty($endpoint)) {
            debugging(
                'User sync failed. Platform URL is not configured.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        $curl = new curl();

        // Set headers properly.
        $curl->setHeader([
            'X-Moodle-Url: ' . $moodleurl,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        // Set curl options.
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
        ];

        try {
            $response = $curl->post(
                $remoteurl,
                $data,
                $options
            );

            // Check curl-level errors.
            if ($curl->get_errno()) {
                debugging(
                    'User sync curl error: ' . $curl->error,
                    DEBUG_DEVELOPER
                );
                return false;
            }

            // Check HTTP status.
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($httpcode < 200 || $httpcode >= 300) {
                debugging(
                    'User sync failed. HTTP Code: ' . $httpcode .
                    ' Response: ' . $response,
                    DEBUG_DEVELOPER
                );
                return false;
            }

            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                debugging(
                    'User sync invalid JSON response: ' . $response,
                    DEBUG_DEVELOPER
                );
                return false;
            }

            return $decoded;
        } catch (\Exception $e) {
            debugging(
                'User sync exception: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }
    }


    /**
     * Marks a user sync record as synced in the local_bservice_user_sync table
     *
     * @param int $userid The ID of the user whose sync record should be marked as synced
     * @return void
     */
    public static function mark_synced($userid) {
        global $DB;

        $record          = $DB->get_record('local_bservice_user_sync', ['userid' => $userid]);
        $record->synced  = 1;
        $DB->update_record('local_bservice_user_sync', $record);
    }

    /**
     * Retrieves users from Moodle that don't have corresponding sync records
     *
     * Finds all non-deleted users in Moodle that don't have an entry in the
     * local_bservice_user_sync table, indicating they haven't been synced yet.
     *
     * @return void Inserts new sync records for each missing user
     */
    public static function sync_missing_users_from_moodle() {
        global $DB;

        $sql = "SELECT u.id, u.username, u.email, u.password
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.id NOT IN (
                            SELECT pu.userid
                              FROM {local_bservice_user_sync} pu)";

        $users = $DB->get_records_sql($sql);

        foreach ($users as $user) {
            $record = self::build_record($user);
            $DB->insert_record('local_bservice_user_sync', $record);
        }
    }

    /**
     * Builds a sync record object for a user
     *
     * Creates a standardized record object containing the user's data that needs to be synced,
     * including username, email, password and site URL. The data is JSON encoded into the payload field.
     *
     * @param object $user The user object to build the record for
     * @return object Record object with payload and synced status fields
     */
    private static function build_record($user) {
        global $CFG;
        $data = json_encode([
            'username' => $user->username,
            'email'    => $user->email,
            'password' => $user->password,
            'url'      => $CFG->wwwroot,
        ]);

        $record          = new stdClass();
        $record->payload = $data;
        $record->synced  = 0;
        $record->userid  = $user->id;
        return $record;
    }
}
