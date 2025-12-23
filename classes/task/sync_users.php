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

use core\task\scheduled_task;
use local_bservicesuite\helper;

/**
 * Class sync_users
 *
 * @package    local_bservicesuite
 * @copyright  2025 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_users extends scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('sync_user_task', 'local_bservicesuite');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        helper::sync_missing_users_from_moodle();
        $records = $DB->get_records('local_bservice_user_sync', ['synced' => 0]);

        foreach ($records as $record) {
            // Here you would add the code to sync the user data with the external service.
            // For demonstration purposes, we'll just mark it as synced.
            $result = helper::sync($record->payload);
            if (!$result) {
                continue;
            }
            $record->synced = 1;
            $DB->update_record('local_bservice_user_sync', $record);
        }
    }
}
