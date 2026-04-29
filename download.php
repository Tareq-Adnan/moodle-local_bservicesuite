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
 * TODO describe file download
 *
 * @package    local_bservicesuite
 * @copyright  2026 Brain Station 23 <sales@brainstation-23.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

require_login();
require_sesskey();

$batchid = required_param('id', PARAM_INT);

require_capability('local/bsservicessuite:view', context_system::instance());
$url = new moodle_url('/local/bservicesuite/download.php', []);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_heading($SITE->fullname);

// Fetch all students in this batch.
$students = $DB->get_records_sql("
    SELECT
        p.userid,
        p.password,
        u.username,
        u.firstname,
        u.lastname,
        u.email
    FROM {local_bservicesuite_passwords} p
    JOIN {user} u ON u.id = p.userid
    WHERE p.batchid = :batchid
      AND p.usertype = 'student'
", ['batchid' => $batchid]);

// Fetch all parents in this batch (keyed by studentid).
$parents = $DB->get_records_sql("
    SELECT
        pm.studentid,
        pm.parentid,
        p.password AS parentpassword,
        u.username  AS parentusername,
        u.firstname AS parentfirstname,
        u.lastname  AS parentlastname,
        u.email     AS parentemail
    FROM {local_bservicesuite_parents} pm
    JOIN {local_bservicesuite_passwords} p ON p.userid = pm.parentid AND p.batchid = :batchid
    JOIN {user} u ON u.id = pm.parentid
    WHERE p.usertype = 'parent'
", ['batchid' => $batchid]);

if (empty($students) || empty($parents)) {
    redirect(
        new moodle_url('/local/bservicesuite/pages/upload_users.php'),
        get_string('nousersfound', 'local_bservicesuite', $batchid),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// Key parents by studentid for easy lookup.
$parentmap = [];
foreach ($parents as $parent) {
    $parentmap[$parent->studentid] = $parent;
}


$filename = 'batch_' . $batchid . '_users_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8 compatibility.
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header row.
fputcsv($output, [
    'username',
    'fullname',
    'email',
    'password',
    'parentusername',
    'parent fullname',
    'parentemail',
    'parentpassword',
]);

// Data rows.
foreach ($students as $student) {
    $parent = $parentmap[$student->userid] ?? null;

    try {
        $studentpass = \core\encryption::decrypt($student->password);
        $parentpass  = $parent ? \core\encryption::decrypt($parent->parentpassword) : '';
    } catch (\Exception $e) {
        $studentpass = '';
        $parentpass  = '';
    }

    fputcsv($output, [
        $student->username,
        trim($student->firstname . ' ' . $student->lastname),
        $student->email,
        $studentpass,
        $parent->parentusername ?? '',
        $parent ? trim($parent->parentfirstname . ' ' . $parent->parentlastname) : '',
        $parent->parentemail ?? '',
        $parentpass,
    ]);
}

fclose($output);
exit;
