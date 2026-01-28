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

use core_user;
use local_bservicesuite\utils\report;

/**
 * Class sent_assesment_report
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sent_assesment_report extends \core\task\adhoc_task {
    /**
     * Get the name of the task
     *
     * @return string The name of the task from language strings
     */
    public function get_name() {
        return get_string('sent_assesment_report', 'local_bservicesuite');
    }
    /**
     * Execute the task to send assessment report
     *
     * @return void
     */
    public function execute() {

        $data = $this->get_custom_data();
        $user = core_user::get_user($data->studentid);
        $parent = report::get_parent($user->id);
        $reportdata = report::get_report_details($data->activityid, $data->courseid, $data->studentid);
        $reportdata->assessmenttype = report::get_assessment_type($reportdata->type);
        $reportdata->course = get_course($data->courseid)->fullname;
        $reportdata->student = fullname($user);

        if ($parent) {
            $user = $parent;
        }
        report::sent_email($reportdata, $user);
    }
}
