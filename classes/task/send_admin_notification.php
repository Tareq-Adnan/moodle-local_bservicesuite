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
 * Class send_admin_notification
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_admin_notification extends \core\task\adhoc_task {
    /**
     * Execute the task of sending admin notification
     *
     * This method sends an email notification to the admin user about a completed batch process.
     * It includes details such as batch ID, completion time, number of users processed, and emails queued.
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;

        $data = $this->get_custom_data();

        if (empty($data->batchid) || empty($data->totalpasswords)) {
            return;
        }

        // Get admin user
        $admin = $DB->get_record('user', ['id' => $this->get_userid()]);

        if (!$admin || empty($admin->email)) {
            return;
        }

        // Get batch details.
        $batch = $DB->get_record('local_bservicesuite_batches', ['id' => $data->batchid]);

        if (!$batch) {
            return;
        }

        // Prepare email content.
        $site = get_site();
        $supportuser = \core_user::get_support_user();

        $a = new \stdClass();
        $a->adminname = fullname($admin);
        $a->batchid = $batch->id;
        $a->timecompleted = userdate($batch->timemodified);
        $a->totalusers = $data->totalpasswords;
        $a->emailsqueued = $batch->emailsqueued;
        $a->site = $site->fullname;
        $a->url = $CFG->wwwroot . '/local/bservicesuite/batchreport.php?id=' . $batch->id;

        $subject = get_string('adminnotificationemailsubject', 'local_bservicesuite', $site->fullname);
        $message = get_string('adminnotificationemail', 'local_bservicesuite', $a);
        $messagehtml = text_to_html($message, false, false, true);

        // Send email.
        email_to_user($admin, $supportuser, $subject, $message, $messagehtml);

        mtrace("Sent admin notification to {$admin->email}");
    }
}
