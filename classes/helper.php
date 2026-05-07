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
use core\exception\invalid_parameter_exception;
use core\exception\moodle_exception;
use core\http_client;
use core_course_category;
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
     * Endpoint URL path for updating course paths in remote system
     * Used to construct the full update URL by appending to the platform base URL
     */
    public const UPDATE_COURSEPATH_ENDPOINT = '/api/course/update';
    /**
     * Endpoint URL path for updating course paths in remote system
     * Used to construct the full update URL by appending to the platform base URL
     */
    public const UPDATE_NEWCOURSE_ENDPOINT = '/api/school-course/update';
    /**
     * Endpoint URL path for creating new course created in central lms
     * Used to construct the full update URL by appending to the platform base URL
     */
    public const CENTRAL_COURSE_CREATE = '/api/course/create';
    /**
     * Endpoint URL path for retrieving platform authentication token
     * Used to construct the full token URL by appending to the platform base URL
     */
    public const PLATFORM_TOKEN = '/api/moodle/token';

    /**
     * Get all visible courses except the site course (id=1)
     *
     * @return array Array of filtered course objects containing id, fullname, shortname and visible fields
     */
    public static function total_courses() {
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

        $user   = $DB->get_record('user', ['id' => $userid], 'id,username,email,password', MUST_EXIST);
        $exists = $DB->get_record('local_bservice_user_sync', ['userid' => $user->id]);

        $record = self::build_record($user);
        if ($exists) {
            $record->id  = $exists->id;
            $DB->update_record('local_bservice_user_sync', $record);
            if ($exists->login_id) {
                $modified = json_decode($record->payload, true);
                $modified['id'] = $exists->login_id;
                $record->payload = json_encode($modified);
            }
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

        [ $curl, $remoteurl, $options ] = self::get_curl(self::USER_SYNC_ENDPOINT);

        if (!$curl) {
            return false;
        }

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
    public static function mark_synced($userid, $result) {
        global $DB;

        $record           = $DB->get_record('local_bservice_user_sync', ['userid' => $userid]);
        $record->synced   = 1;
        $record->login_id = isset($result['id']) ? $result['id'] : 0;
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


    /**
     * Creates and configures a curl instance for API requests
     *
     * @param string $apiend The API endpoint path to append to the platform URL
     * @return array | bool Returns configured curl instance, remote URL, and options or false if platform URL not set
     */
    public static function get_curl($apiend) {
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');
        $endpoint  = get_config('local_bservicesuite', 'platformurl');
        $remoteurl = $endpoint . $apiend;
        $moodleurl = $CFG->wwwroot;
        $isschool = get_config('local_bservicesuite', 'is_school');

        if (empty($endpoint)) {
            debugging(
                'Platform URL is not configured.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        $header = [
            'X-Moodle-Url: ' . $moodleurl,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($isschool) {
            $platformtoken = get_config('local_bservicesuite', 'platform_token');
            if (empty($platformtoken)) {
                $platformtoken = self::get_platform_token();
            }

            if (!empty($platformtoken)) {
                $header[] = 'Authorization: Bearer ' . $platformtoken;
            }
        }

        $curl = new curl();

        // Set headers properly.
        $curl->setHeader($header);

        // Set curl options.
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
        ];

        return [$curl, $remoteurl, $options];
    }

    /**
     * Retrieves the platform authentication token
     *
     * @return string The platform authentication token
     */
    public static function get_platform_token() {
        global $CFG;
        $client = new http_client();
        $endpoint  = get_config('local_bservicesuite', 'platformurl');
        $apiurl = $endpoint . self::PLATFORM_TOKEN;

        $payload = [
            'school_url' => $CFG->wwwroot,
        ];

        try {
            $response = $client->post($apiurl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!empty($result['success']) && !empty($result['token'])) {
                // Save token in Moodle config.
                set_config('platform_token', $result['token'], 'local_bservicesuite');

                return $result['token'];
            } else {
                debugging('Token API failed: ' . ($result['message'] ?? 'Unknown error'));
                return false;
            }
        } catch (moodle_exception $e) {
            debugging('Token API exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Creates a new course in the platform by sending course data to remote system
     *
     * Takes a Moodle course ID and sends the course details to the remote platform
     * via API call to create a corresponding course record there.
     *
     * @param int $courseid The ID of the Moodle course to create in platform
     * @return void
     */
    public static function create_or_update_platform_course($courseid, $update = false) {
        global $DB;

        $isschool = get_config('local_bservicesuite', 'is_school');
        if (intval($isschool)) {
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        $category = core_course_category::get($course->category);
        [$curl, $remoteurl, $options] = self::get_curl(self::CENTRAL_COURSE_CREATE);

        if (!$curl) {
            throw new moodle_exception('coursecreatefail', 'local_bservicesuite', '', 'Platform URL not configured');
        }

        $data = [
            'id'         => $course->id,
            'fullname'   => $course->fullname,
            'shortname'  => $course->shortname,
            'grade'      => $course->category,
            'grade_name' => $category->name,
            'status'     => 'available',
        ];

        if ($update) {
            $data['coursepath'] = "reset";
        }

        try {
            $response = $curl->post(
                $remoteurl,
                json_encode($data),
                $options
            );

            // Check curl-level errors.
            if ($curl->get_errno()) {
                debugging(
                    'Platform course create error: ' . $curl->error,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('coursecreatefail', 'local_bservicesuite', '', $curl->error);
            }

            // Check HTTP status.
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($httpcode < 200 || $httpcode >= 300) {
                debugging(
                    'Platform course create failed. HTTP Code: ' . $httpcode .
                    ' Response: ' . $response,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('coursecreatefail', 'local_bservicesuite', '', 'HTTP Code: ' . $httpcode);
            }

            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                debugging(
                    'Platform course create invalid response: ' . $response,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('coursecreatefail', 'local_bservicesuite', '', 'Invalid JSON response');
            }

            if (empty($decoded['status'])) {
                throw new moodle_exception('coursecreatefail', 'local_bservicesuite', '', $decoded);
            }
        } catch (\Exception $e) {
            debugging(
                'Curl exception: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            throw new moodle_exception('coursecreatefail', 'local_bservicesuite', '', $e->getMessage());
        }
    }

    /**
     * Deletes multiple courses from the system
     *
     * Iterates through an array of course IDs and deletes each course from the database.
     * Only deletes courses that exist in the system.
     *
     * @param array $courseids Array of course IDs to delete
     * @return bool
     */
    public static function perform_delete($courseids) {
        global $DB;
        foreach ($courseids as $id) {
            if ($course = $DB->get_record('course', ['id' => $id])) {
                return delete_course($course, false);
            }
        }
        return false;
    }


    /**
     * Updates the site name in Moodle configuration and database
     *
     * This method cleans and validates the provided site name, then updates both
     * the Moodle configuration and the front-page course record to maintain
     * synchronization. It also purges all caches to ensure the changes take effect.
     *
     * @param string $sitename The new site name to set
     * @return array Array containing status and the updated fullname
     * @throws \invalid_parameter_exception If the site name is blank after cleaning
     */
    public static function update_sitename($sitename) {
        global $DB;

        $cleanfull = clean_param(trim($sitename), PARAM_TEXT);

        if ($cleanfull === '') {
            throw new \invalid_parameter_exception('fullname cannot be blank after cleaning.');
        }
        // Update mdl_config (used by many core components).
        set_config('fullname', $cleanfull);

        // Also update the front-page course record so $SITE->fullname stays in sync.
        $DB->set_field('course', 'fullname', $cleanfull, ['id' => SITEID]);

        purge_all_caches();
        $site = get_site();

        return [
            'status'   => true,
            'fullname'  => $site->fullname,
        ];
    }

    /**
     * Updates a user's email address in the database
     *
     * Finds a user by their current email address and updates it to a new email address.
     * If no user is found with the current email, no action is taken.
     *
     * @param string $currentemail The current email address to search for
     * @param string $wantemail The new email address to update to
     * @return void
     */
    public static function update_manager_email(string $currentemail, string $wantemail) {
        global $DB;

        $user = $DB->get_record('user', ['email' => $currentemail]);
        if (!$user) {
            return; // No user found, nothing to do.
        }

        // Check the new email isn't already taken by someone else.
        $conflict = $DB->get_record('user', ['email' => $wantemail]);
        if ($conflict && $conflict->id !== $user->id) {
            throw new \invalid_parameter_exception("Email '{$wantemail}' is already in use.");
        }

        $DB->update_record('user', ['id' => $user->id, 'email' => $wantemail]);
    }


    /**
     * Fetches an image from a remote URL and saves it as the site logo across:
     *   - core_admin  : logo, logocompact, favicon
     *   - theme_edash : main_logo, mobile_logo, favicon, main_footer_logo
     *
     * Follows Moodle's standard admin_setting_configstoredfile conventions:
     * each stored-file setting uses the config key name as both the filearea
     * and the config value (which Moodle sets to '1' when a file is present).
     *
     * @param  string $logourl  Publicly accessible image URL provided by Laravel
     * @param  string $filename Filename used when storing (e.g. "logo.png")
     * @return void
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function update_system_logo(string $logourl, string $filename = 'logo.png'): void {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        mtrace("update_system_logo: Starting logo update. URL: {$logourl}, Filename: {$filename}");

        $tempfile = tempnam($CFG->tempdir, 'sitelogo_');
        mtrace("update_system_logo: Temp file created at: {$tempfile}");

        $params = [];
        $options = ['filepath' => $tempfile];
        $curl = new curl();
        $result = $curl->download_one($logourl, $params, $options);

        if ($result !== true || $curl->get_errno()) {
            mtrace("update_system_logo: ERROR - Failed to download logo. cURL errno: " . $curl->get_errno() . ", Error: " . $curl->error);
            if (file_exists($tempfile)) {
                @unlink($tempfile);
            }
            throw new \moodle_exception(
                'generalexceptionmessage',
                'error',
                '',
                'Failed to fetch logo. ' . $curl->error
            );
        }

        mtrace("update_system_logo: Logo downloaded successfully. File size: " . filesize($tempfile) . " bytes.");

        if (!file_exists($tempfile) || filesize($tempfile) === 0) {
            mtrace("update_system_logo: ERROR - Downloaded file is missing or empty.");
            @unlink($tempfile);
            throw new \invalid_parameter_exception('Downloaded file is empty.');
        }

        if (!empty($curl->get_errno())) {
            mtrace("update_system_logo: ERROR - cURL error detected post-download. errno: " . $curl->get_errno() . ", Error: " . $curl->error);
            @unlink($tempfile);
            throw new \moodle_exception(
                'generalexceptionmessage',
                'error',
                '',
                'Failed to fetch logo. cURL error ' . $curl->get_errno() . ': ' . $curl->error
            );
        }

        if (!file_exists($tempfile) || filesize($tempfile) === 0) {
            mtrace("update_system_logo: ERROR - Downloaded file is empty after second check.");
            @unlink($tempfile);
            throw new \invalid_parameter_exception(
                'Downloaded file is empty. Ensure the URL is publicly accessible.'
            );
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimetype = $finfo->file($tempfile);
        mtrace("update_system_logo: Detected MIME type: {$mimetype}");

        $allowedmimes = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/svg+xml',
        'image/webp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        ];

        if (!in_array($mimetype, $allowedmimes, true)) {
            mtrace("update_system_logo: ERROR - Unsupported MIME type '{$mimetype}'.");
            @unlink($tempfile);
            throw new \invalid_parameter_exception(
                "Unsupported image type '{$mimetype}'. Allowed: " . implode(', ', $allowedmimes)
            );
        }

        try {
            $fs         = get_file_storage();
            $syscontext = context_system::instance();
            $coreadminareas = ['logo', 'logocompact', 'favicon'];

            mtrace("update_system_logo: Processing core_admin file areas: " . implode(', ', $coreadminareas));
            foreach ($coreadminareas as $filearea) {
                $fs->delete_area_files($syscontext->id, 'core_admin', $filearea, 0);
                mtrace("update_system_logo: Deleted existing files in core_admin/{$filearea}.");

                $fs->create_file_from_pathname(
                    [
                    'contextid' => $syscontext->id,
                    'component' => 'core_admin',
                    'filearea'  => $filearea,
                    'itemid'    => 0,
                    'filepath'  => '/',
                    'filename'  => $filename,
                    ],
                    $tempfile
                );
                set_config($filearea, "/$filename", 'core_admin');
                mtrace("update_system_logo: Stored file and set config for core_admin/{$filearea}.");
            }

            set_config('logocontextid', $syscontext->id);
            set_config('logocompactcontextid', $syscontext->id);
            mtrace("update_system_logo: Set logocontextid and logocompactcontextid to context ID: {$syscontext->id}.");

            $edashareas = [
            'main_logo',
            'mobile_logo',
            'favicon',
            'main_footer_logo',
            ];

            mtrace("update_system_logo: Processing theme_edash file areas: " . implode(', ', $edashareas));
            foreach ($edashareas as $filearea) {
                $fs->delete_area_files($syscontext->id, 'theme_edash', $filearea, 0);
                mtrace("update_system_logo: Deleted existing files in theme_edash/{$filearea}.");

                $fs->create_file_from_pathname(
                    [
                    'contextid' => $syscontext->id,
                    'component' => 'theme_edash',
                    'filearea'  => $filearea,
                    'itemid'    => 0,
                    'filepath'  => '/',
                    'filename'  => $filename,
                    ],
                    $tempfile
                );

                set_config($filearea, "/$filename", 'theme_edash');
                set_config("logo_image_width", "120", 'theme_edash');
                set_config("logo_image_height", "60", 'theme_edash');
                mtrace("update_system_logo: Stored file and set config for theme_edash/{$filearea}.");
            }
        } catch (\Throwable $e) {
            mtrace("update_system_logo: EXCEPTION during file storage - " . get_class($e) . ": " . $e->getMessage());
            mtrace("update_system_logo: Stack trace: " . $e->getTraceAsString());
            throw $e; // Re-throw so the adhoc task can handle/retry it.
        } finally {
            @unlink($tempfile);
            mtrace("update_system_logo: Temp file cleaned up.");
        }

        theme_reset_all_caches();
        mtrace("update_system_logo: Theme caches reset. Logo update complete.");
    }
}
