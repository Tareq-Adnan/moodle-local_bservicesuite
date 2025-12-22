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
        $data = helper::create_or_update_user($userid);
        $result = helper::sync($data->payload);
        $result ? helper::mark_synced($userid) : null;
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
        $result ? helper::mark_synced($userid) : null;
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
}
