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
use core\task\adhoc_task;
use local_bservicesuite\utils\backup_helper;

/**
 * Class restore_from_s3
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_from_s3 extends adhoc_task {
    /**
     * Execute the task.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $categoryid = $data->grade;
        $userid = $data->userid;
        $coursepath = $data->coursepath;
        $gradename = $data->gradename;
        $ccourse = $data->course; // Central course id.
        $schoolid = $data->school_id;
        backup_helper::perform_restore($coursepath, $categoryid, $userid, $gradename, $ccourse, $schoolid);
    }
}
