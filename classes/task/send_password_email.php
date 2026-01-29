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

namespace local_bservicesuite\task;

/**
 * Class send_password_email
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_password_email extends \core\task\adhoc_task {
    /**
     * Execute the task of sending a password email to a user.
     *
     * This method handles sending an email containing login credentials to a newly created user.
     * It performs the following steps:
     * 1. Validates the required task data (userid, password, batchid)
     * 2. Retrieves and validates the user record
     * 3. Prepares the email content with user details and site information
     * 4. Sends the email to the user
     * 5. Updates the password record status
     * 6. Updates batch statistics if email was sent successfully
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;

        $data = $this->get_custom_data();

        if (empty($data->userid) || empty($data->password) || empty($data->batchid)) {
            mtrace("Invalid task data");
            return;
        }

        // Get user
        $user = $DB->get_record('user', ['id' => $data->userid]);

        if (!$user || empty($user->email) || !validate_email($user->email)) {
            // Mark as failed
            $this->update_password_record($data->batchid, $data->userid, 0, true);
            return;
        }

        // Prepare email content
        $site = get_site();
        $supportuser = \core_user::get_support_user();

        $a = new \stdClass();
        $a->firstname = $user->firstname;
        $a->lastname = $user->lastname;
        $a->username = $user->username;
        $a->password = $data->password;
        $a->email = $data->email;
        $a->site = $site->fullname;
        $a->url = $CFG->wwwroot;
        $a->signoff = generate_email_signoff();

        $subject = get_string('newuserpasswordemailsubject', 'local_bservicesuite', $site->fullname);
        $message = get_string('newuserpasswordemailgenerated', 'local_bservicesuite', $a);
        $messagehtml = text_to_html($message, false, false, true);

        // Send email
        $emailsent = email_to_user($user, $supportuser, $subject, $message, $message);

        // Update password record
        $this->update_password_record($data->batchid, $data->userid, $emailsent ? 1 : 0);

        // Update batch statistics
        if ($emailsent) {
            $this->update_batch_stats($data->batchid);
        }

        mtrace("Sent password email to {$user->email} (User ID: {$user->id})");
    }

    /**
     * Updates the password record in the database for a given batch and user.
     *
     * @param int $batchid The ID of the batch containing the password record
     * @param int $userid The ID of the user whose password record is being updated
     * @param int $sent Status flag indicating if email was sent (1), not sent (0), or failed (-1)
     * @param bool $failed Optional flag to mark the record as failed
     * @return void
     */
    private function update_password_record($batchid, $userid, $sent, $failed = false) {
        global $DB;

        $record = $DB->get_record('local_bservicesuite_passwords', [
            'batchid' => $batchid,
            'userid' => $userid,
        ]);

        if ($record) {
            $record->emailsent = $sent;
            $record->emailsenttime = time();
            if ($failed) {
                $record->emailsent = -1; // Mark as failed
            }
            $DB->update_record('local_bservicesuite_passwords', $record);
        }
    }

    /**
     * Updates the statistics for a password email batch.
     *
     * @param int $batchid The ID of the batch to update
     * @return void
     */
    private function update_batch_stats($batchid) {
        global $DB;

        $batch = $DB->get_record('local_bservicesuite_batches', ['id' => $batchid]);

        if ($batch) {
            $batch->emailssent++;
            $DB->update_record('local_bservicesuite_batches', $batch);
        }
    }
}
