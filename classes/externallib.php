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
use context_user;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_user;
use moodle_exception;


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
            ['courseid' => $courseid]
        );

        has_all_capabilities(['local/bsservicessuite:view'], context_system::instance());

        $response = [
            'data' => [],
            'logs' => [],
            'totalcourse' => 0,
            'totaluser' => 0,
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
            'totaluser' => $users,
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
                        'enrolled' => new external_value(PARAM_INT, 'Total number of enrolled user')])
                ),
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
                            ]),
                    'logs',
                    VALUE_OPTIONAL
                ),
                'totalcourse' => new external_value(PARAM_INT, 'Total number of courses'),
                'totaluser' => new external_value(PARAM_INT, 'Total number of courses'),

            ],
        );
    }


    /**
     * Returns description of update_update_profile parameters
     *
     * @return external_function_parameters Parameters structure
     */
    public static function update_profile_parameters() {

        $userfields = [
            'id' => new external_value(core_user::get_property_type('id'), 'ID of the user'),
            'username' => new external_value(core_user::get_property_type('username'), 'username', VALUE_OPTIONAL),
            'firstname' => new external_value(
                core_user::get_property_type('firstname'),
                'The first name(s) of the user',
                VALUE_OPTIONAL,
                '',
                NULL_NOT_ALLOWED
            ),
            'lastname' => new external_value(
                core_user::get_property_type('lastname'),
                'The family name of the user',
                VALUE_OPTIONAL
            ),
            'email' => new external_value(
                core_user::get_property_type('email'),
                'A valid and unique email address',
                VALUE_OPTIONAL,
                '',
                NULL_NOT_ALLOWED
            ),
            'city' => new external_value(core_user::get_property_type('city'), 'Home city of the user', VALUE_OPTIONAL),
            'country' => new external_value(core_user::get_property_type('country')),
        ];

        return new external_function_parameters(
            ['users' => new external_multiple_structure(new external_single_structure($userfields))]
        );
    }

    /**
     * Update user profile information
     *
     * @param array $users
     * @return array
     */
    public static function update_profile($users) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . "/user/lib.php");
        require_once($CFG->dirroot . "/user/profile/lib.php"); // Required for customfields related function.
        require_once($CFG->dirroot . '/user/editlib.php');

        $params = self::validate_parameters(self::update_profile_parameters(), ['users' => $users]);

        $warnings = [];
        foreach ($params['users'] as $user) {
            $context = context_user::instance($user['id']);
            self::validate_context($context);
            require_capability('local/bsservicessuite:updateownprofile', $context);

            // Catch any exception while updating a user and return it as a warning.
            try {
                $transaction = $DB->start_delegated_transaction();

                // First check the user exists.
                if (!$existinguser = core_user::get_user($user['id'])) {
                    throw new moodle_exception(
                        'invaliduserid',
                        '',
                        '',
                        null,
                        'Invalid user ID'
                    );
                }
                // Check if we are trying to update an admin.
                if ($existinguser->id != $USER->id && is_siteadmin($existinguser) && !is_siteadmin($USER)) {
                    throw new moodle_exception(
                        'usernotupdatedadmin',
                        '',
                        '',
                        null,
                        'Cannot update admin accounts'
                    );
                }
                // Other checks (deleted, remote or guest users).
                if ($existinguser->deleted) {
                    throw new moodle_exception(
                        'usernotupdateddeleted',
                        'local_bservicesuite',
                        '',
                        null,
                        'User is a deleted user'
                    );
                }

                if (isguestuser($existinguser->id)) {
                    throw new moodle_exception(
                        'usernotupdatedguest',
                        'local_bservicesuite',
                        '',
                        null,
                        'Cannot update guest account'
                    );
                }
                // Check duplicated emails.
                if (isset($user['email']) && $user['email'] !== $existinguser->email) {
                    if (!validate_email($user['email'])) {
                        throw new moodle_exception(
                            'useremailinvalid',
                            'local_bservicesuite',
                            '',
                            null,
                            'Invalid email address'
                        );
                    } else if (empty($CFG->allowaccountssameemail)) {
                        // Make a case-insensitive query for the given email address
                        // and make sure to exclude the user being updated.
                        $select = $DB->sql_equal('email', ':email', false) . ' AND mnethostid = :mnethostid AND id <> :userid';
                        $params = [
                            'email' => $user['email'],
                            'mnethostid' => $CFG->mnet_localhost_id,
                            'userid' => $user['id'],
                        ];
                        // Skip if there are other user(s) that already have the same email.
                        if ($DB->record_exists_select('user', $select, $params)) {
                            throw new moodle_exception(
                                'useremailduplicate',
                                '',
                                '',
                                null,
                                'Duplicate email address'
                            );
                        }
                    }
                }

                user_update_user($user, false, true);

                $userobject = (object)$user;

                // Trigger event.
                \core\event\user_updated::create_from_userid($user['id'])->trigger();

                if (isset($user['suspended']) && $user['suspended']) {
                    \core\session\manager::destroy_user_sessions($user['id']);
                }

                $transaction->allow_commit();
            } catch (moodle_exception $e) {
                try {
                    $transaction->rollback($e);
                } catch (moodle_exception $e) {
                    $warning = [];
                    $warning['item'] = 'user';
                    $warning['itemid'] = $user['id'];
                    if ($e instanceof moodle_exception) {
                        $warning['warningcode'] = $e->errorcode;
                    } else {
                        $warning['warningcode'] = $e->getCode();
                    }
                    $warning['message'] = $e->getMessage();
                    $warnings[] = $warning;
                }
            }
        }
        return ['warnings' => $warnings];
    }

    /**
     * Returns description of update_profile return values
     *
     * @return external_single_structure Return value structure
     */
    public static function update_profile_returns() {
        return new external_single_structure(
            [
                'warnings' => new \core_external\external_warnings(),
            ]
        );
    }
}
