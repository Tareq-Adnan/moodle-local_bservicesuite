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

/**
 * Class csv_user_upload
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_bservicesuite\upload;

use context_system;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

    /**
     * Process CSV file upload
     */
class csv_user_upload {
    /**
     * @var int|null User ID to notify about upload completion
     */
    private $notifyuser;
    /**
     * @var int|null sessionid to notify about upload completion
     */
    private $sessionid;

    /**
     * Constructor for csv_user_upload class
     *
     * @param string|null $sessionid Session ID for tracking batch uploads
     * @param int|null $notifyuser User ID to notify about upload completion
     */
    public function __construct($sessionid = null, $notifyuser = null) {
        $this->sessionid = $sessionid;
        $this->notifyuser = $notifyuser;
    }

    /**
     * Process CSV file upload
     */
    public function process_upload($csvcontent, $options = []) {
        global $CFG, $DB;

        // Default options
        $defaults = [
            'encoding' => 'UTF-8',
            'delimiter' => 'comma',
            'updateexisting' => true,
            'createnewcohorts' => true,
            'emailpassword' => true,
            // 'forcepasswordchange' => true,
        ];

        $options = array_merge($defaults, $options);

        // Parse CSV content
        $iid = \csv_import_reader::get_new_iid('local_bservicesuite');
        $cir = new \csv_import_reader($iid, 'local_bservicesuite');

        $delimiter = $options['delimiter'] == 'comma' ? ',' : ($options['delimiter'] == 'tab' ? "\t" : ';');

        $readcount = $cir->load_csv_content($csvcontent, $options['encoding'], $delimiter);

        if (!$readcount) {
            throw new \moodle_exception('csvloaderror', 'local_bservicesuite');
        }

        // Get CSV columns
        $columns = $cir->get_columns();

        // Define required columns (without password columns).
        $requiredcolumns = [
            'username', 'firstname', 'lastname', 'email',
            'grade', 'section',
            'parentusername', 'parentfirstname', 'parentlastname', 'parentemail',
        ];

        foreach ($requiredcolumns as $col) {
            if (!in_array($col, $columns)) {
                throw new \moodle_exception('missingcolumn', 'local_bservicesuite', null, $col);
            }
        }

        // Create a batch record for tracking
        $batchid = $this->create_batch_record();

        // Process records
        $results = [
            'batchid' => $batchid,
            'students_created' => 0,
            'students_updated' => 0,
            'parents_created' => 0,
            'parents_updated' => 0,
            'cohorts_created' => 0,
            'cohorts_existing' => 0,
            'enrollments' => 0,
            'passwords_generated' => 0,
            'emails_queued' => 0,
            'errors' => [],
            'generated_passwords' => [], // For immediate display
        ];

        $cir->init();

        while ($record = $cir->next()) {
            try {
                $record = array_combine($columns, $record);
                $this->process_record($record, $results, $options, $batchid);
            } catch (\Exception $e) {
                $results['errors'][] = $e->getMessage() . " (Record: " . implode(', ', $record) . ")";
            }
        }

        $cir->cleanup();
        $cir->close();

        // Update batch record with final stats
        $this->update_batch_record($batchid, $results);

        // Queue notification email to admin if requested
        if ($this->notifyuser && $options['emailpassword']) {
            $this->queue_admin_notification($batchid, $this->notifyuser, count($results['generated_passwords']));
        }

        return $results;
    }

    /**
     * Process a single CSV record
     */
    private function process_record($record, &$results, $options, $batchid) {
        global $DB;

        // Generate passwords for new users
        $studentpassword = generate_password();
        $parentpassword = generate_password();

        // 1. Create or update parent user with generated password
        $parent = $this->create_or_update_user(
            $record['parentusername'],
            $record['parentfirstname'],
            $record['parentlastname'],
            $record['parentemail'],
            'parent',
            $parentpassword,
            $results,
            $options['updateexisting'],
            $options['forcepasswordchange'],
            $batchid
        );

        // 2. Create or update student user with generated password
        $student = $this->create_or_update_user(
            $record['username'],
            $record['firstname'],
            $record['lastname'],
            $record['email'],
            'student',
            $studentpassword,
            $results,
            $options['updateexisting'],
            $options['forcepasswordchange'],
            $batchid
        );

        // 3. Create cohort based on grade and section
        $cohort = $this->create_or_update_cohort(
            $record['grade'],
            $record['section'],
            $results,
            $options['createnewcohorts']
        );

        if ($cohort) {
            // 4. Add student to cohort
            $this->add_user_to_cohort($student->id, $cohort->id, $results);

            // 5. Map student to parent (using custom field or user relationship)
            $this->map_student_to_parent($student->id, $parent->id, $results);
        }

        // 6. Queue password emails if requested
        if ($options['emailpassword']) {
            // For student
            if (!empty($student->email) && validate_email($student->email)) {
                $this->queue_password_email(
                    $student->id,
                    $studentpassword,
                    $batchid,
                    'student',
                    $student->email,
                );
                $results['emails_queued']++;
            }

            // For parent
            if (!empty($parent->email) && validate_email($parent->email)) {
                $this->queue_password_email(
                    $parent->id,
                    $parentpassword,
                    $batchid,
                    'parent',
                    $parent->email,
                );
                $results['emails_queued']++;
            }
        }

        // Store generated passwords for immediate display
        if ($studentpassword && $results['students_created'] > 0) {
            $results['generated_passwords'][] = [
                'username' => $student->username,
                'password' => $studentpassword,
                'email' => $student->email,
                'name' => $student->firstname . ' ' . $student->lastname,
                'type' => 'student',
            ];
        }

        if ($parentpassword && $results['parents_created'] > 0) {
            $results['generated_passwords'][] = [
                'username' => $parent->username,
                'password' => $parentpassword,
                'email' => $parent->email,
                'name' => $parent->firstname . ' ' . $parent->lastname,
                'type' => 'parent',
            ];
        }

        if ($studentpassword || $parentpassword) {
            $results['passwords_generated']++;
        }
    }

    /**
     * Create or update user with generated password
     */
    private function create_or_update_user($username, $firstname, $lastname, $email, $roletype, $password, &$results, $updateexisting, $forcepasswordchange, $batchid) {
        global $DB, $CFG;

        // Check if user exists
        $user = $DB->get_record('user', ['username' => $username]);
        $isnew = false;

        if ($user) {
            if ($updateexisting) {
                // Update existing user - don't change password
                $user->firstname = $firstname;
                $user->lastname = $lastname;
                $user->email = $email;
                $user->confirmed = 1;
                $user->mnethostid = $CFG->mnet_localhost_id;

                user_update_user($user, false, true);

                if ($roletype == 'student') {
                    $results['students_updated']++;
                } else {
                    $results['parents_updated']++;
                }
            }
        } else {
            // Create new user with generated password
            $user = new \stdClass();
            $user->username = $username;
            $user->firstname = $firstname;
            $user->lastname = $lastname;
            $user->email = $email;
            $user->password = hash_internal_user_password($password);
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->auth = 'manual';
            $user->lang = $CFG->lang;
            $user->timecreated = time();
            $user->timemodified = time();

            // Force password change on first login.
            $user->id = user_create_user($user, false, true);

            // if ($forcepasswordchange) {
            //     set_user_preference('auth_forcepasswordchange', 1, $user);
            // }

            $isnew = true;

            if ($roletype == 'student') {
                $results['students_created']++;
            } else {
                $results['parents_created']++;
            }

            // Store password in batch table for email sending
            if ($isnew && $password) {
                $this->store_password_for_batch($user->id, $password, $batchid, $roletype);
            }
        }

        // Assign system role based on type
        if ($isnew) {
            $this->assign_system_role($user->id, $roletype);
        }

        return $user;
    }

    /**
     * Store password for batch processing
     */
    private function store_password_for_batch($userid, $password, $batchid, $usertype) {
        global $DB;

        $record = new \stdClass();
        $record->batchid = $batchid;
        $record->userid = $userid;
        $record->password = $password;
        $record->usertype = $usertype;
        $record->timecreated = time();
        $record->emailsent = 0;

        $DB->insert_record('local_bservicesuite_passwords', $record);
    }

    /**
     * Queue password email via adhoc task
     */
    private function queue_password_email($userid, $password, $batchid, $usertype, $email) {
        global $DB, $USER;

        $task = new \local_bservicesuite\task\send_password_email();
        $task->set_userid($USER->id);

        $task->set_custom_data([
            'userid' => $userid,
            'password' => $password,
            'batchid' => $batchid,
            'usertype' => $usertype,
            'email' => $email,
        ]);

        // Queue the task
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Create batch record for tracking
     */
    private function create_batch_record() {
        global $DB, $USER;

        $batch = new \stdClass();
        $batch->userid = $USER->id;
        $batch->timecreated = time();
        $batch->timemodified = time();
        $batch->status = 'processing';
        $batch->totalusers = 0;
        $batch->emailsqueued = 0;
        $batch->emailssent = 0;
        $batch->sessionid = $this->sessionid;

        return $DB->insert_record('local_bservicesuite_batches', $batch);
    }

    /**
     * Update batch record
     */
    private function update_batch_record($batchid, $results) {
        global $DB;

        $batch = $DB->get_record('local_bservicesuite_batches', ['id' => $batchid]);

        if ($batch) {
            $batch->status = 'completed';
            $batch->totalusers = $results['students_created'] + $results['parents_created'];
            $batch->emailsqueued = $results['emails_queued'];
            $batch->timemodified = time();
            $batch->resultdata = json_encode([
                'students_created' => $results['students_created'],
                'parents_created' => $results['parents_created'],
                'cohorts_created' => $results['cohorts_created'],
            ]);

            $DB->update_record('local_bservicesuite_batches', $batch);
        }
    }

    /**
     * Queue admin notification
     */
    private function queue_admin_notification($batchid, $adminid, $totalpasswords) {
        $task = new \local_bservicesuite\task\send_admin_notification();
        $task->set_userid($adminid);

        $task->set_custom_data([
            'batchid' => $batchid,
            'totalpasswords' => $totalpasswords,
        ]);

        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Assign system role to user
     */
    private function assign_system_role($userid, $roletype) {
        global $DB;

        // Get role IDs (you might want to make these configurable)
        $roleid = null;
        if ($roletype == 'student') {
            $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        } else if ($roletype == 'parent') {
            // Create or get parent role
            $roleid = $this->get_or_create_parent_role();
        }

        if ($roleid) {
            // Check if role assignment already exists
            $exists = $DB->record_exists('role_assignments', [
                'userid' => $userid,
                'roleid' => $roleid,
                'contextid' => \context_system::instance()->id,
            ]);

            if (!$exists) {
                role_assign($roleid, $userid, \context_system::instance()->id);
            }
        }
    }

    /**
     * Get or create parent role
     */
    private function get_or_create_parent_role() {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'parent']);

        if (!$role) {
            // Create parent role
            $parentroleid = create_role(
                get_string('parent', 'local_bservicesuite'),
                'parent',
                get_string('parentdescription', 'local_bservicesuite'),
                'parent'
            );

            set_role_contextlevels($parentroleid, [
                CONTEXT_SYSTEM,
                CONTEXT_USER,
            ]);

            // Set some capabilities (adjust as needed).
            $context = context_system::instance();
            assign_capability('moodle/user:viewdetails', CAP_ALLOW, $parentroleid, $context->id);
            assign_capability('moodle/grade:view', CAP_ALLOW, $parentroleid, $context->id);

            return $parentroleid;
        }

        return $role->id;
    }

    /**
     * Create or update cohort
     */
    private function create_or_update_cohort($grade, $section, &$results, $createnew) {
        global $DB;

        // Clean grade and section values
        $grade = trim($grade);
        $section = trim($section);

        // Create cohort name in format: grade_{grade}_section_{section}
        $cohortname = "grade_{$grade}_section_{$section}";
        $cohortidnumber = "grade_{$grade}_section_{$section}";

        // Check if cohort exists
        $cohort = $DB->get_record('cohort', ['idnumber' => $cohortidnumber]);

        if ($cohort) {
            $results['cohorts_existing']++;
        } else if ($createnew) {
            // Create new cohort
            $cohort = new \stdClass();
            $cohort->name = $cohortname;
            $cohort->idnumber = $cohortidnumber;
            $cohort->description = "Cohort for Grade {$grade}, Section {$section}";
            $cohort->contextid = context_system::instance()->id;
            $cohort->visible = 1;
            $cohort->component = 'local_bservicesuite';
            $cohort->timecreated = time();
            $cohort->timemodified = time();

            $cohort->id = cohort_add_cohort($cohort);
            $results['cohorts_created']++;
        } else {
            return null;
        }

        return $cohort;
    }

    /**
     * Add user to cohort
     */
    private function add_user_to_cohort($userid, $cohortid, &$results) {
        global $DB;

        // Check if already enrolled
        $exists = $DB->record_exists('cohort_members', [
            'cohortid' => $cohortid,
            'userid' => $userid,
        ]);

        if (!$exists) {
            cohort_add_member($cohortid, $userid);
            $results['enrollments']++;
        }
    }

    /**
     * Map student to parent
     */
    private function map_student_to_parent($studentid, $parentid, &$results) {
        global $DB;

        // Check if mapping already exists
        $exists = $DB->record_exists("local_bservicesuite_parents", [
            'studentid' => $studentid,
            'parentid' => $parentid,
        ]);

        if (!$exists) {
            $record = new \stdClass();
            $record->studentid = $studentid;
            $record->parentid = $parentid;
            $record->timecreated = time();

            $DB->insert_record('local_bservicesuite_parents', $record);
        }
    }
}
